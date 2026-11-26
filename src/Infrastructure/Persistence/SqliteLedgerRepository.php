<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Persistence;

use MamaMboga\Application\Port\LedgerRepository;
use MamaMboga\Domain\Ledger\EntryType;
use MamaMboga\Domain\Ledger\LedgerEntry;
use MamaMboga\Domain\Money\Money;

/** There is deliberately no update() or delete() here, and the table's triggers refuse them too. */
final class SqliteLedgerRepository implements LedgerRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function entriesFor(int $customerId): array
    {
        return array_map($this->hydrate(...), $this->db->all('SELECT * FROM ledger_entries WHERE customer_id = ? ORDER BY id', [$customerId]));
    }

    public function append(LedgerEntry $entry): LedgerEntry
    {
        $this->db->execute(
            'INSERT INTO ledger_entries (customer_id, entry_type, amount_kes, method, receipt_code, order_id, note, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$entry->customerId, $entry->type->value, $entry->amount->amount, $entry->method, $entry->receiptCode, $entry->orderId, $entry->note, $entry->occurredAt->format('c')],
        );

        return new LedgerEntry($this->db->lastId(), $entry->customerId, $entry->type, $entry->amount, $entry->occurredAt, $entry->method, $entry->receiptCode, $entry->orderId, $entry->note);
    }

    public function balances(): array
    {
        $rows = $this->db->all(
            "SELECT customer_id, SUM(CASE WHEN entry_type = 'charge' THEN amount_kes ELSE -amount_kes END) AS balance
             FROM ledger_entries GROUP BY customer_id",
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['customer_id']] = Money::kes((int) $row['balance']);
        }

        return $out;
    }

    public function entriesOn(string $date): array
    {
        return array_map($this->hydrate(...), $this->db->all('SELECT * FROM ledger_entries WHERE substr(occurred_at, 1, 10) = ? ORDER BY id', [$date]));
    }

    public function countPaymentsWithMethodOn(string $date, string $method): int
    {
        return $this->db->scalar(
            "SELECT COUNT(*) FROM ledger_entries WHERE entry_type = 'payment' AND method = ? AND substr(occurred_at, 1, 10) = ?",
            [$method, $date],
        );
    }

    /** @param array<string, mixed> $r */
    private function hydrate(array $r): LedgerEntry
    {
        return new LedgerEntry(
            (int) $r['id'],
            (int) $r['customer_id'],
            EntryType::from((string) $r['entry_type']),
            Money::kes((int) $r['amount_kes']),
            new \DateTimeImmutable((string) $r['occurred_at']),
            $r['method'] === null ? null : (string) $r['method'],
            $r['receipt_code'] === null ? null : (string) $r['receipt_code'],
            $r['order_id'] === null ? null : (int) $r['order_id'],
            (string) $r['note'],
        );
    }
}
