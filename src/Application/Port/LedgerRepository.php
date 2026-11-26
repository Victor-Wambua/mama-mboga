<?php

declare(strict_types=1);

namespace MamaMboga\Application\Port;

use MamaMboga\Domain\Ledger\LedgerEntry;
use MamaMboga\Domain\Money\Money;

interface LedgerRepository
{
    /** @return list<LedgerEntry> oldest first */
    public function entriesFor(int $customerId): array;

    public function append(LedgerEntry $entry): LedgerEntry;

    /** @return array<int, Money> customer id => balance owed */
    public function balances(): array;

    /** @return list<LedgerEntry> written on a calendar day (Y-m-d) */
    public function entriesOn(string $date): array;

    public function countPaymentsWithMethodOn(string $date, string $method): int;
}
