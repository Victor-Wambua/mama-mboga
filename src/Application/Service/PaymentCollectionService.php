<?php

declare(strict_types=1);

namespace MamaMboga\Application\Service;

use MamaMboga\Application\Collection\Attention;
use MamaMboga\Application\Collection\PaymentRequest;
use MamaMboga\Application\Gateway\GatewayException;
use MamaMboga\Application\Gateway\GatewayPayment;
use MamaMboga\Application\Gateway\GatewayStatus;
use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Port\CustomerRepository;
use MamaMboga\Application\Port\GatewayClient;
use MamaMboga\Application\Port\PaymentRequestRepository;
use MamaMboga\Application\Port\Transaction;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Money\Money;

/**
 * Collect a customer's deni with an M-Pesa prompt.
 *
 * The one rule everything here serves: the deni book is written only when the gateway reports `succeeded`,
 * and then only through `LedgerService::recordPayment()`, so the book's own rules (a receipt code used once,
 * never more than is owed) still apply. Anything the gateway is unsure about (`needs_review`, a late success,
 * a success whose callback and status check disagree) is shown to the owner and never recorded by this class.
 */
final class PaymentCollectionService
{
    /** How long a timed-out prompt keeps being checked, in case a late success turns up. */
    public const LATE_WATCH_DAYS = 2;

    private const REFERENCE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        private readonly PaymentRequestRepository $requests,
        private readonly LedgerService $ledger,
        private readonly CustomerRepository $customers,
        private readonly GatewayClient $gateway,
        private readonly Transaction $tx,
        private readonly Clock $clock,
    ) {
    }

    public function enabled(): bool
    {
        return $this->gateway->isConfigured();
    }

    /**
     * Ask the gateway to send a prompt to the customer's own number for `$amount` (default: the whole balance).
     *
     * @throws ValidationException
     */
    public function request(int $customerId, ?int $amount = null): PaymentRequest
    {
        if (!$this->enabled()) {
            throw ValidationException::field('amount', 'The payments gateway is not set up. Set GATEWAY_URL and GATEWAY_SECRET_KEY.');
        }

        return $this->submit($this->open($customerId, $amount));
    }

    /**
     * Save a request without contacting the gateway: the amount is checked against the balance, and the
     * request gets its short id (the gateway `reference`) and a stable idempotency key. The browser demo
     * calls this, then sends the prompt from the page, because PHP running in the browser has no network.
     *
     * @param int|null $charge what the gateway takes, when it differs from `$amount` (the demo's KES 1 sandbox test)
     * @throws ValidationException
     */
    public function open(int $customerId, ?int $amount = null, string $mode = PaymentRequest::MODE_LIVE, ?int $charge = null, ?int $limit = null): PaymentRequest
    {
        $customer = $this->customers->find($customerId) ?? throw ValidationException::field('customer', 'Customer not found.');

        return $this->tx->run(function () use ($customer, $customerId, $amount, $mode, $charge, $limit): PaymentRequest {
            $balance = $this->ledger->ledgerFor($customerId)->balance();
            if (!$balance->isPositive()) {
                throw ValidationException::field('amount', "{$customer->name} owes nothing, so there is nothing to collect.");
            }
            $amount ??= $balance->amount;
            $most = $limit === null ? $balance->amount : min($balance->amount, $limit);
            if ($amount < 1 || $amount > $most) {
                throw ValidationException::field('amount', $limit !== null && $limit < $balance->amount
                    ? "Enter an amount from KSh 1 up to KSh {$most}: the balance is KSh {$balance->amount}, and a single prompt is limited to KSh {$limit} here."
                    : "Enter an amount from KSh 1 up to the KSh {$balance->amount} owed.");
            }
            foreach ($this->requests->forCustomer($customerId) as $existing) {
                if ($existing->waiting()) {
                    throw ValidationException::field('amount', 'A prompt for this customer is still waiting. Check its status before sending another.');
                }
            }

            $now = $this->clock->now();

            return $this->requests->add(new PaymentRequest(
                null,
                $this->newReference(),
                $customerId,
                $amount,
                $charge ?? $amount,
                $mode,
                $mode === PaymentRequest::MODE_LIVE ? $customer->phone->e164 : null,
                'mm-' . bin2hex(random_bytes(12)),
                null,
                GatewayStatus::Pending,
                [],
                null,
                '',
                null,
                null,
                '',
                null,
                '',
                $now,
                $now,
            ));
        });
    }

    /**
     * Send the prompt for a saved request. Safe to repeat: the request id and idempotency key do not change,
     * so a request the gateway already has comes back as the same payment and no second prompt goes out.
     *
     * @throws ValidationException
     */
    public function submit(PaymentRequest $request): PaymentRequest
    {
        [$after, $problem] = $this->send($request);
        if ($problem !== null) {
            throw ValidationException::field('amount', $problem);
        }

        return $after;
    }

    /**
     * Link a payment the gateway created (from the browser demo) to its request, then apply what it reports.
     *
     * @throws ValidationException
     */
    public function attach(int $requestId, GatewayPayment $payment): PaymentRequest
    {
        return $this->apply($requestId, $payment, linking: true);
    }

    /**
     * Ask the gateway where a request stands and act on the answer. Safe to call as often as you like: a request
     * already in the book is returned unchanged, and a payment is recorded at most once.
     */
    public function reconcile(int $requestId): PaymentRequest
    {
        $request = $this->requests->find($requestId) ?? throw ValidationException::field('request', 'Request not found.');
        if ($request->recorded() || $request->attention !== null) {
            return $request; // in the book, or waiting for the owner: nothing here changes either on its own
        }
        if (!$request->sent()) {
            if ($request->status === GatewayStatus::Failed) {
                return $request; // the gateway refused it outright: sending it again would be refused again
            }

            return $this->send($request)[0];
        }
        try {
            $payment = $this->gateway->getPayment((string) $request->gatewayPaymentId);
        } catch (GatewayException $e) {
            $noted = $request->with(['lastError' => $e->getMessage(), 'updatedAt' => $this->clock->now()]);
            $this->requests->save($noted);

            return $noted;
        }

        return $this->apply($requestId, $payment, linking: false);
    }

    /**
     * Reconcile every request still worth asking about. Meant for a cron job or `bin/console gateway:reconcile`.
     *
     * @return list<PaymentRequest> the requests after reconciling
     */
    public function reconcileOpen(): array
    {
        $out = [];
        $since = $this->clock->now()->modify('-' . self::LATE_WATCH_DAYS . ' days');
        foreach ($this->requests->open($since) as $request) {
            $out[] = $this->reconcile((int) $request->id);
        }

        return $out;
    }

    /**
     * The owner has looked at an item that needed them. This only marks it as seen: it never writes to the book.
     * If the money did arrive, record it with the usual payment form and its M-Pesa code.
     *
     * @throws ValidationException
     */
    public function markReviewed(int $requestId, string $note): PaymentRequest
    {
        $request = $this->requests->find($requestId) ?? throw ValidationException::field('request', 'Request not found.');
        if ($request->attention === null) {
            throw ValidationException::field('request', 'Nothing here needs you.');
        }
        $note = trim($note);
        $now = $this->clock->now();
        $reviewed = $request->with(['attentionNote' => $note === '' ? $request->attentionNote : $note, 'reviewedAt' => $now, 'updatedAt' => $now]);
        $this->requests->save($reviewed);

        return $reviewed;
    }

    /**
     * Give up on a request the gateway never accepted. A request that was sent cannot be abandoned:
     * it is settled by the gateway's answer, so the owner can never lose track of a prompt that went out.
     *
     * @throws ValidationException
     */
    public function abandon(int $requestId, string $reason): PaymentRequest
    {
        $request = $this->requests->find($requestId) ?? throw ValidationException::field('request', 'Request not found.');
        if ($request->sent() || $request->recorded()) {
            throw ValidationException::field('request', 'That prompt was sent, so it ends when the gateway reports how it ended.');
        }
        $closed = $request->with(['status' => GatewayStatus::Failed, 'lastError' => trim($reason), 'updatedAt' => $this->clock->now()]);
        $this->requests->save($closed);

        return $closed;
    }

    /** @return list<PaymentRequest> */
    public function forCustomer(int $customerId): array
    {
        return $this->requests->forCustomer($customerId);
    }

    /** @return list<PaymentRequest> items waiting for the owner */
    public function needingOwner(): array
    {
        return $this->requests->needingOwner();
    }

    public function find(int $requestId): ?PaymentRequest
    {
        return $this->requests->find($requestId);
    }

    /**
     * @return array{0: PaymentRequest, 1: string|null} the request afterwards, and what to tell the owner if it did not go out
     */
    private function send(PaymentRequest $request): array
    {
        if ($request->sent()) {
            return [$request, null];
        }
        if ($request->phone === null) {
            return [$request, 'This request has no phone number to send to.'];
        }
        try {
            $payment = $this->gateway->createPayment($request->reference, $request->charge, $request->phone, 'Deni payment', $request->idempotencyKey);
        } catch (GatewayException $e) {
            $failed = $request->with(['lastError' => $e->getMessage(), 'updatedAt' => $this->clock->now(), 'status' => $e->retryable() ? $request->status : GatewayStatus::Failed]);
            $this->requests->save($failed);

            return [$failed, $this->sendFailure($e)];
        }

        return [$this->apply((int) $request->id, $payment, linking: true), null];
    }

    // ---- the reconcile step ----------------------------------------------------------------

    /**
     * Take what the gateway reports for this request and move the request, and only when it is safe the book, to match.
     * Runs in one transaction that re-reads the request first, so two callers cannot both record the same payment.
     */
    private function apply(int $requestId, GatewayPayment $payment, bool $linking): PaymentRequest
    {
        return $this->tx->run(function () use ($requestId, $payment, $linking): PaymentRequest {
            $request = $this->requests->find($requestId) ?? throw ValidationException::field('request', 'Request not found.');
            if ($request->recorded()) {
                return $request; // already in the book: replays, repeated polls and duplicate callbacks end here
            }
            if ($request->gatewayPaymentId !== null && $request->gatewayPaymentId !== $payment->id) {
                throw ValidationException::field('request', 'That payment belongs to a different request.');
            }
            $owner = $this->requests->findByGatewayPaymentId($payment->id);
            if ($owner !== null && $owner->id !== $request->id) {
                throw ValidationException::field('request', 'That payment is already attached to another request.');
            }
            if (!$linking && $request->gatewayPaymentId === null) {
                throw ValidationException::field('request', 'This request was never sent.');
            }

            $previous = $request->status;
            $attention = null;
            $note = '';
            $entryId = null;

            if ($payment->reference !== $request->reference || $payment->amount !== $request->charge) {
                $attention = Attention::DetailsMismatch;
                $note = "Asked for KSh {$request->charge} as {$request->reference}; the gateway reports KSh {$payment->amount} as {$payment->reference}.";
            } elseif ($payment->status === GatewayStatus::NeedsReview) {
                $attention = Attention::fromFlags($payment->flags);
            } elseif ($payment->status === GatewayStatus::Succeeded) {
                $blocking = Attention::blockingFlag($payment->flags);
                if ($blocking !== null) {
                    $attention = $blocking;
                } elseif (in_array($previous, [GatewayStatus::Failed, GatewayStatus::Cancelled, GatewayStatus::TimedOut, GatewayStatus::NeedsReview], true) && $request->sent()) {
                    $attention = Attention::StatusChanged;
                    $note = "It was {$previous->value}.";
                } elseif ($payment->receipt === null || preg_match('/^[A-Z0-9]{10}$/', $payment->receipt) !== 1) {
                    $attention = Attention::DetailsMismatch;
                    $note = 'The gateway sent no usable M-Pesa code.';
                } else {
                    try {
                        $entryId = $this->record($request, $payment);
                    } catch (ValidationException $e) {
                        $attention = Attention::BookRefused;
                        $note = implode(' ', $e->errors) . ' Nothing was written. Check M-Pesa, then correct the book by hand if the money arrived.';
                    }
                }
            }

            $updated = $request->with([
                'gatewayPaymentId' => $payment->id,
                'status' => $payment->status,
                'flags' => $payment->flags,
                'receipt' => $payment->receipt ?? $request->receipt,
                'resultDesc' => $payment->resultDesc,
                'ledgerEntryId' => $entryId,
                'attention' => $attention,
                'attentionNote' => $note,
                'lastError' => '',
                'updatedAt' => $this->clock->now(),
            ]);
            $this->requests->save($updated);

            return $updated;
        });
    }

    /** @throws ValidationException when the book refuses the entry (receipt already recorded, more than is owed) */
    private function record(PaymentRequest $request, GatewayPayment $payment): int
    {
        $entry = $this->ledger->recordPayment(
            $request->customerId,
            Money::kes($request->amount),
            'mpesa',
            (string) $payment->receipt,
            $this->ledgerNote($request, $payment),
        );

        return (int) $entry->id;
    }

    private function ledgerNote(PaymentRequest $request, GatewayPayment $payment): string
    {
        return match ($request->mode) {
            PaymentRequest::MODE_MOCK => "Mock gateway, simulated, no real money. Prompt {$request->reference}, payment {$payment->id}.",
            PaymentRequest::MODE_SANDBOX => "Sandbox test: KSh {$request->charge} charged stands in for KSh {$request->amount}. Prompt {$request->reference}, payment {$payment->id}.",
            default => "M-Pesa prompt {$request->reference}, payment {$payment->id}.",
        };
    }

    private function sendFailure(GatewayException $e): string
    {
        return match ($e->kind) {
            GatewayException::RATE_LIMITED => 'The gateway is limiting requests. Wait ' . ($e->retryAfterSeconds ?? 30) . ' seconds, then use Check status. The request is saved and cannot go out twice.',
            GatewayException::UNREACHABLE, GatewayException::SERVER => 'Could not reach the gateway, so the request is saved but may not have been sent. Use Check status to try again: the same request id is reused, so it cannot go out twice.',
            GatewayException::NOT_CONFIGURED => 'The payments gateway is not set up. Set GATEWAY_URL and GATEWAY_SECRET_KEY.',
            default => 'The gateway did not accept this request: ' . $e->getMessage() . ' Nothing was sent.',
        };
    }

    private function newReference(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = 'DENI-';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }
            if (!$this->requests->referenceExists($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique request id.');
    }
}
