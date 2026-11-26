<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Money;

/** The ways a greengrocer sells produce. "Heap" is the Swahili rundo. */
enum Unit: string
{
    case Kg = 'kg';
    case Bunch = 'bunch';
    case Piece = 'piece';
    case Heap = 'heap';

    public function label(): string
    {
        return match ($this) {
            self::Kg => 'kg',
            self::Bunch => 'bunch',
            self::Piece => 'piece',
            self::Heap => 'heap',
        };
    }

    public function labelSw(): string
    {
        return match ($this) {
            self::Kg => 'kilo',
            self::Bunch => 'mkungu',
            self::Piece => 'kipande',
            self::Heap => 'rundo',
        };
    }

    /** Smallest orderable amount, in thousandths of a unit. */
    public function increment(): int
    {
        return $this === self::Kg ? 250 : 1000;
    }

    /** Step used by the stepper buttons, in thousandths. */
    public function step(): int
    {
        return $this === self::Kg ? 500 : 1000;
    }

    public static function tryFromLabel(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
