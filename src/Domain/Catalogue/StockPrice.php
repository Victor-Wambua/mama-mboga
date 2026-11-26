<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Catalogue;

use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;
use MamaMboga\Domain\Order\OrderLine;

/**
 * One line of today's price board: an item, how it is sold, what it costs
 * this morning and how much is left on the stall.
 */
final readonly class StockPrice
{
    /** @param list<string> $aliases */
    public function __construct(
        public ?int $entryId,
        public int $itemId,
        public string $nameEn,
        public string $nameSw,
        public array $aliases,
        public Unit $unit,
        public Money $price,
        public Quantity $available,
        public bool $special = false,
        public string $specialNote = '',
    ) {
        if (!$price->isPositive()) {
            throw new \InvalidArgumentException('Price must be more than zero.');
        }
    }

    public function isSoldOut(): bool
    {
        return $this->available->isZero();
    }

    public function validAmount(Quantity $quantity): bool
    {
        return $quantity->isPositive() && $quantity->isMultipleOf($this->unit->increment());
    }

    public function canFulfil(Quantity $quantity): bool
    {
        return $this->validAmount($quantity) && $this->available->atLeast($quantity);
    }

    public function reserve(Quantity $quantity): self
    {
        if (!$this->canFulfil($quantity)) {
            throw new \InvalidArgumentException("Cannot reserve {$quantity->decimal()} of {$this->nameEn}.");
        }

        return new self(
            $this->entryId,
            $this->itemId,
            $this->nameEn,
            $this->nameSw,
            $this->aliases,
            $this->unit,
            $this->price,
            $this->available->subtract($quantity),
            $this->special,
            $this->specialNote,
        );
    }

    public function lineFor(Quantity $quantity): OrderLine
    {
        if (!$this->validAmount($quantity)) {
            throw new \InvalidArgumentException("Invalid amount for {$this->nameEn}.");
        }

        return new OrderLine($this->itemId, $this->nameEn, $this->nameSw, $this->unit, $quantity, $this->price);
    }
}
