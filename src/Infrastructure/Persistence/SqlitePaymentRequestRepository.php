<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Persistence;

use MamaMboga\Application\Collection\Attention;
use MamaMboga\Application\Collection\PaymentRequest;
use MamaMboga\Application\Gateway\GatewayStatus;
use MamaMboga\Application\Port\PaymentRequestRepository;

/** Requests are ordinary mutable rows. The ledger they point at is not: it is append-only and never touched from here. */
final class SqlitePaymentRequestRepository implements PaymentRequestRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function add(PaymentRequest $request): PaymentRequest
    {
        $this->db->execute(
            'INSERT INTO payment_requests (reference, customer_id, amount_kes, charge_kes, mode, phone, idempotency_key, gateway_payment_id, status, flags, receipt, result_desc,
                ledger_entry_id, attention, attention_note, reviewed_at, last_error, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->values($request),
        );

        return new PaymentRequest(
            $this->db->lastId(),
            $request->reference,
            $request->customerId,
            $request->amount,
            $request->charge,
            $request->mode,
            $request->phone,
            $request->idempotencyKey,
            $request->gatewayPaymentId,
            $request->status,
            $request->flags,
            $request->receipt,
            $request->resultDesc,
            $request->ledgerEntryId,
            $request->attention,
            $request->attentionNote,
            $request->reviewedAt,
            $request->lastError,
            $request->createdAt,
            $request->updatedAt,
        );
    }

    public function save(PaymentRequest $request): void
    {
        if ($request->id === null) {
            throw new \LogicException('Add the request before saving it.');
        }
        $this->db->execute(
            'UPDATE payment_requests SET reference = ?, customer_id = ?, amount_kes = ?, charge_kes = ?, mode = ?, phone = ?, idempotency_key = ?, gateway_payment_id = ?, status = ?,
                flags = ?, receipt = ?, result_desc = ?, ledger_entry_id = ?, attention = ?, attention_note = ?, reviewed_at = ?, last_error = ?, created_at = ?, updated_at = ?
             WHERE id = ?',
            [...$this->values($request), $request->id],
        );
    }

    public function find(int $id): ?PaymentRequest
    {
        $row = $this->db->one('SELECT * FROM payment_requests WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByGatewayPaymentId(string $paymentId): ?PaymentRequest
    {
        $row = $this->db->one('SELECT * FROM payment_requests WHERE gateway_payment_id = ?', [$paymentId]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function referenceExists(string $reference): bool
    {
        return $this->db->scalar('SELECT COUNT(*) FROM payment_requests WHERE reference = ?', [$reference]) > 0;
    }

    public function forCustomer(int $customerId): array
    {
        return array_map($this->hydrate(...), $this->db->all('SELECT * FROM payment_requests WHERE customer_id = ? ORDER BY id DESC', [$customerId]));
    }

    public function open(\DateTimeImmutable $lateSince): array
    {
        return array_map($this->hydrate(...), $this->db->all(
            "SELECT * FROM payment_requests
             WHERE ledger_entry_id IS NULL AND attention IS NULL
               AND (status = 'pending' OR (status = 'timed_out' AND created_at >= ?))
             ORDER BY id",
            [$lateSince->format('c')],
        ));
    }

    public function needingOwner(): array
    {
        return array_map($this->hydrate(...), $this->db->all('SELECT * FROM payment_requests WHERE attention IS NOT NULL AND reviewed_at IS NULL ORDER BY id'));
    }

    /** @return list<scalar|null> */
    private function values(PaymentRequest $r): array
    {
        return [
            $r->reference,
            $r->customerId,
            $r->amount,
            $r->charge,
            $r->mode,
            $r->phone,
            $r->idempotencyKey,
            $r->gatewayPaymentId,
            $r->status->value,
            implode(',', $r->flags),
            $r->receipt,
            $r->resultDesc,
            $r->ledgerEntryId,
            $r->attention?->value,
            $r->attentionNote,
            $r->reviewedAt?->format('c'),
            $r->lastError,
            $r->createdAt->format('c'),
            $r->updatedAt->format('c'),
        ];
    }

    /** @param array<string, mixed> $r */
    private function hydrate(array $r): PaymentRequest
    {
        $flags = (string) $r['flags'];

        return new PaymentRequest(
            (int) $r['id'],
            (string) $r['reference'],
            (int) $r['customer_id'],
            (int) $r['amount_kes'],
            (int) $r['charge_kes'],
            (string) $r['mode'],
            $r['phone'] === null ? null : (string) $r['phone'],
            (string) $r['idempotency_key'],
            $r['gateway_payment_id'] === null ? null : (string) $r['gateway_payment_id'],
            GatewayStatus::from((string) $r['status']),
            $flags === '' ? [] : explode(',', $flags),
            $r['receipt'] === null ? null : (string) $r['receipt'],
            (string) $r['result_desc'],
            $r['ledger_entry_id'] === null ? null : (int) $r['ledger_entry_id'],
            $r['attention'] === null ? null : Attention::from((string) $r['attention']),
            (string) $r['attention_note'],
            $r['reviewed_at'] === null ? null : new \DateTimeImmutable((string) $r['reviewed_at']),
            (string) $r['last_error'],
            new \DateTimeImmutable((string) $r['created_at']),
            new \DateTimeImmutable((string) $r['updated_at']),
        );
    }
}
