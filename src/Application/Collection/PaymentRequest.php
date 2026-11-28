<?php

declare(strict_types=1);

namespace MamaMboga\Application\Collection;

use MamaMboga\Application\Gateway\GatewayStatus;

/**
 * One M-Pesa prompt the owner asked the gateway to send to a customer, to collect deni.
 *
 * `amount` is what the book is credited with; `charge` is what the gateway is asked to take. They are
 * equal, except in the browser demo's sandbox mode, where a flat KES 1 test charge stands in for the amount.
 * `status` mirrors the gateway. A request with no `gatewayPaymentId` has not been accepted by the gateway yet.
 */
final readonly class PaymentRequest
{
    public const MODE_LIVE = 'live';
    public const MODE_MOCK = 'mock';
    public const MODE_SANDBOX = 'sandbox';

    /** @param list<string> $flags */
    public function __construct(
        public ?int $id,
        public string $reference,
        public int $customerId,
        public int $amount,
        public int $charge,
        public string $mode,
        public ?string $phone,
        public string $idempotencyKey,
        public ?string $gatewayPaymentId,
        public GatewayStatus $status,
        public array $flags,
        public ?string $receipt,
        public string $resultDesc,
        public ?int $ledgerEntryId,
        public ?Attention $attention,
        public string $attentionNote,
        public ?\DateTimeImmutable $reviewedAt,
        public string $lastError,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * A copy with some fields changed, by constructor name: `$r->with(status: GatewayStatus::Failed)`.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    public function sent(): bool
    {
        return $this->gatewayPaymentId !== null;
    }

    public function recorded(): bool
    {
        return $this->ledgerEntryId !== null;
    }

    /** Needs the owner and has not been looked at yet. */
    public function needsOwner(): bool
    {
        return $this->attention !== null && $this->reviewedAt === null;
    }

    /** Still waiting on the gateway for an answer (a request that was never sent counts: it is retried). */
    public function waiting(): bool
    {
        return !$this->recorded() && $this->attention === null && $this->status === GatewayStatus::Pending;
    }

    /** Worth asking the gateway about again: not in the book, not waiting for the owner. */
    public function checkable(): bool
    {
        return !$this->recorded() && $this->attention === null
            && in_array($this->status, [GatewayStatus::Pending, GatewayStatus::TimedOut, GatewayStatus::Succeeded], true);
    }

    /**
     * What to show the owner. Plain words, and never "paid" before the book has the payment.
     *
     * @return array{state: string, label: string, detail: string}
     */
    public function display(): array
    {
        if ($this->recorded()) {
            $how = match ($this->mode) {
                self::MODE_MOCK => ' This was the simulated mock gateway: no real money moved.',
                self::MODE_SANDBOX => ' This was a KES 1 sandbox test charge standing in for the amount.',
                default => '',
            };

            return ['state' => 'recorded', 'label' => 'Paid and recorded', 'detail' => "In the book with M-Pesa code {$this->receipt}.{$how}"];
        }
        if ($this->attention !== null) {
            return $this->reviewedAt !== null
                ? ['state' => 'reviewed', 'label' => 'Looked at by you', 'detail' => trim($this->attention->label() . ' ' . $this->attentionNote)]
                : ['state' => 'needs_owner', 'label' => 'Needs you', 'detail' => trim($this->attention->label() . ' ' . $this->attentionNote)];
        }

        return match ($this->status) {
            GatewayStatus::Pending => $this->sent()
                ? ['state' => 'waiting', 'label' => 'Waiting', 'detail' => 'The customer has not answered yet, or the gateway is still confirming a reply with Safaricom. It is not paid until the gateway says succeeded.']
                : ['state' => 'not_sent', 'label' => 'Not sent yet', 'detail' => 'The gateway has not accepted this request. Check status sends it again; the same request id is reused, so it cannot go out twice.'],
            GatewayStatus::Succeeded => ['state' => 'waiting', 'label' => 'Confirmed, recording', 'detail' => 'The gateway says succeeded. Check status to write it in the book.'],
            GatewayStatus::Failed => ['state' => 'failed', 'label' => 'Did not go through', 'detail' => ($this->resultDesc !== '' ? $this->resultDesc : ($this->lastError !== '' ? $this->lastError : 'The gateway reports a failure.')) . ' Nothing was recorded.'],
            GatewayStatus::Cancelled => ['state' => 'cancelled', 'label' => 'Cancelled on the phone', 'detail' => 'The customer dismissed the prompt. Nothing was recorded.'],
            GatewayStatus::TimedOut => ['state' => 'timed_out', 'label' => 'No answer in time', 'detail' => 'Nothing was recorded. If an approval arrives late, it will show up here for you to check; it is never recorded by itself.'],
            GatewayStatus::NeedsReview => ['state' => 'needs_owner', 'label' => 'Needs you', 'detail' => 'The gateway holds this payment for review. It is not recorded.'],
        };
    }
}
