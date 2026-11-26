<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Ledger;

use MamaMboga\Domain\Money\Money;

/**
 * A customer's deni book. It is append-only: `append()` returns a new Ledger
 * and never touches an existing entry. Invariants enforced on every append:
 *
 *  - the balance never goes below zero (no overpaying into a negative);
 *  - a charge never pushes the balance above the credit limit;
 *  - a receipt code is used once;
 *  - an order is charged once;
 *  - entries cannot be dated before the entry already at the end of the book.
 */
final readonly class Ledger
{
    /** @param list<LedgerEntry> $entries in the order they were written */
    private function __construct(
        public int $customerId,
        public Money $creditLimit,
        public array $entries,
    ) {
    }

    /** @param list<LedgerEntry> $entries */
    public static function open(int $customerId, Money $creditLimit, array $entries = []): self
    {
        $ledger = new self($customerId, $creditLimit, []);
        foreach ($entries as $entry) {
            $ledger = $ledger->append($entry, enforceLimit: false);
        }

        return $ledger;
    }

    public function balance(): Money
    {
        $balance = Money::zero();
        foreach ($this->entries as $entry) {
            $balance = $balance->add($entry->signed());
        }

        return $balance;
    }

    public function available(): Money
    {
        $room = $this->creditLimit->subtract($this->balance());

        return $room->isNegative() ? Money::zero() : $room;
    }

    public function append(LedgerEntry $entry, bool $enforceLimit = true): self
    {
        if ($entry->customerId !== $this->customerId) {
            throw new LedgerViolation('That entry belongs to a different customer.');
        }

        $last = $this->entries === [] ? null : $this->entries[array_key_last($this->entries)];
        if ($last !== null && $entry->occurredAt < $last->occurredAt) {
            throw new LedgerViolation('Entries cannot be back-dated before the latest entry.');
        }

        foreach ($this->entries as $existing) {
            if ($entry->receiptCode !== null && $existing->receiptCode === $entry->receiptCode) {
                throw new LedgerViolation("Receipt {$entry->receiptCode} has already been recorded.");
            }
            if ($entry->type === EntryType::Charge && $entry->orderId !== null && $existing->orderId === $entry->orderId && $existing->type === EntryType::Charge) {
                throw new LedgerViolation('That order has already been charged.');
            }
        }

        $after = $this->balance()->add($entry->signed());
        if ($after->isNegative()) {
            throw new LedgerViolation(sprintf(
                '%s is more than the %s owed.',
                $entry->amount->format(),
                $this->balance()->format(),
            ));
        }
        if ($enforceLimit && $entry->type === EntryType::Charge && $after->greaterThan($this->creditLimit)) {
            throw new LedgerViolation(sprintf(
                'This would take the balance to %s, over the %s credit limit.',
                $after->format(),
                $this->creditLimit->format(),
            ));
        }

        return new self($this->customerId, $this->creditLimit, [...$this->entries, $entry]);
    }

    /**
     * Statement rows with a running balance.
     *
     * @return list<array{entry: LedgerEntry, balance: Money}>
     */
    public function statement(): array
    {
        $rows = [];
        $running = Money::zero();
        foreach ($this->entries as $entry) {
            $running = $running->add($entry->signed());
            $rows[] = ['entry' => $entry, 'balance' => $running];
        }

        return $rows;
    }

    /**
     * Oldest-first allocation: every payment and credit note is applied to the
     * oldest charge still open, then what is left is bucketed by charge age.
     */
    public function aging(\DateTimeImmutable $asOf): Aging
    {
        /** @var list<array{at: \DateTimeImmutable, left: int}> $open */
        $open = [];
        $credit = 0;
        foreach ($this->entries as $entry) {
            if ($entry->type->increasesBalance()) {
                $open[] = ['at' => $entry->occurredAt, 'left' => $entry->amount->amount];
                continue;
            }
            $credit += $entry->amount->amount;
        }

        foreach ($open as &$charge) {
            if ($credit <= 0) {
                break;
            }
            $take = min($credit, $charge['left']);
            $charge['left'] -= $take;
            $credit -= $take;
        }
        unset($charge);

        $buckets = [0, 0, 0, 0];
        foreach ($open as $charge) {
            if ($charge['left'] === 0) {
                continue;
            }
            $days = (int) $charge['at']->setTime(0, 0)->diff($asOf->setTime(0, 0))->format('%r%a');
            $index = match (true) {
                $days <= 7 => 0,
                $days <= 14 => 1,
                $days <= 30 => 2,
                default => 3,
            };
            $buckets[$index] += $charge['left'];
        }

        return new Aging(Money::kes($buckets[0]), Money::kes($buckets[1]), Money::kes($buckets[2]), Money::kes($buckets[3]));
    }
}
