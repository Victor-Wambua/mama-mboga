<?php

declare(strict_types=1);

namespace MamaMboga\Application\Port;

interface ShoppingCheckRepository
{
    /** @return array<string, true> keys "itemId:unit" that have been bought */
    public function boughtOn(string $date): array;

    public function mark(string $date, int $itemId, string $unit, bool $bought): void;
}
