<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Order;

use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;

/** A snapshot: name and price as they were when the order was placed. */
final readonly class OrderLine
{
    public function __construct(
        public int $itemId,
        public string $nameEn,
        public string $nameSw,
        public Unit $unit,
        public Quantity $quantity,
        public Money $unitPrice,
    ) {
    }

    public function total(): Money
    {
        return $this->unitPrice->timesMilli($this->quantity->milli);
    }
}
