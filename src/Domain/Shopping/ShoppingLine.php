<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Shopping;

use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;

final readonly class ShoppingLine
{
    public function __construct(
        public int $itemId,
        public string $nameEn,
        public string $nameSw,
        public Unit $unit,
        public Quantity $quantity,
        public int $orderCount,
    ) {
    }

    public function key(): string
    {
        return $this->itemId . ':' . $this->unit->value;
    }
}
