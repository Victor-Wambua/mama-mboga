<?php

declare(strict_types=1);

namespace MamaMboga\Application\Service;

use MamaMboga\Application\Port\OrderRepository;
use MamaMboga\Application\Port\ShoppingCheckRepository;
use MamaMboga\Domain\Shopping\ShoppingLine;
use MamaMboga\Domain\Shopping\ShoppingList;

final class ShoppingListService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ShoppingCheckRepository $checks,
    ) {
    }

    /** @return list<array{line: ShoppingLine, bought: bool}> */
    public function forDate(string $date): array
    {
        $bought = $this->checks->boughtOn($date);

        return array_map(
            static fn (ShoppingLine $line): array => ['line' => $line, 'bought' => isset($bought[$line->key()])],
            ShoppingList::fromOrders($this->orders->forDate($date)),
        );
    }

    public function mark(string $date, int $itemId, string $unit, bool $bought): void
    {
        $this->checks->mark($date, $itemId, $unit, $bought);
    }
}
