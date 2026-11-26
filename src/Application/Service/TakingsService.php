<?php

declare(strict_types=1);

namespace MamaMboga\Application\Service;

use MamaMboga\Application\Port\LedgerRepository;
use MamaMboga\Application\Port\OrderRepository;
use MamaMboga\Domain\Order\DailyTakings;

final class TakingsService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly LedgerRepository $ledger,
    ) {
    }

    public function forDate(string $date): DailyTakings
    {
        return DailyTakings::summarise($this->orders->forDate($date), $this->ledger->entriesOn($date));
    }
}
