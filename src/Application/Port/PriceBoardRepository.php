<?php

declare(strict_types=1);

namespace MamaMboga\Application\Port;

use MamaMboga\Domain\Catalogue\Item;
use MamaMboga\Domain\Catalogue\StockPrice;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;

interface PriceBoardRepository
{
    /** @return list<Item> */
    public function items(): array;

    public function item(int $id): ?Item;

    public function saveItem(Item $item): Item;

    /** @return list<StockPrice> */
    public function board(string $date): array;

    public function entry(int $entryId): ?StockPrice;

    public function latestBoardDateBefore(string $date): ?string;

    public function saveEntry(string $date, int $itemId, Unit $unit, Money $price, Quantity $available, bool $special, string $specialNote): void;

    public function removeEntry(int $entryId): void;

    /** Atomically change stock by a delta in thousandths; false if it would go below zero or the entry is gone. */
    public function adjustAvailable(int $entryId, int $deltaMilli): bool;

    public function adjustAvailableByItem(string $date, int $itemId, int $deltaMilli): void;
}
