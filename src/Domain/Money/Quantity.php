<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Money;

/** An amount of produce in thousandths of a unit (1500 = 1.5 kg). */
final readonly class Quantity
{
    private function __construct(public int $milli)
    {
    }

    public static function milli(int $milli): self
    {
        if ($milli < 0) {
            throw new \InvalidArgumentException('Quantity cannot be negative.');
        }

        return new self($milli);
    }

    public static function units(int $units): self
    {
        return self::milli($units * 1000);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** Parse "2", "1.5", "0,5" or "1.25"; at most three decimals. */
    public static function fromDecimal(string $value): self
    {
        $value = str_replace(',', '.', trim($value));
        if (preg_match('/^(\d{1,6})(?:\.(\d{1,3}))?$/', $value, $m) !== 1) {
            throw new \InvalidArgumentException('Not a valid quantity: ' . $value);
        }

        $fraction = str_pad($m[2] ?? '', 3, '0');

        return new self(((int) $m[1]) * 1000 + (int) $fraction);
    }

    public function add(self $other): self
    {
        return new self($this->milli + $other->milli);
    }

    public function subtract(self $other): self
    {
        return self::milli($this->milli - $other->milli);
    }

    public function isZero(): bool
    {
        return $this->milli === 0;
    }

    public function isPositive(): bool
    {
        return $this->milli > 0;
    }

    public function atLeast(self $other): bool
    {
        return $this->milli >= $other->milli;
    }

    public function equals(self $other): bool
    {
        return $this->milli === $other->milli;
    }

    public function isMultipleOf(int $increment): bool
    {
        return $increment > 0 && $this->milli % $increment === 0;
    }

    /** "2", "1.5", "0.25": trailing zeros trimmed. */
    public function decimal(): string
    {
        $whole = intdiv($this->milli, 1000);
        $fraction = $this->milli % 1000;
        if ($fraction === 0) {
            return (string) $whole;
        }

        return $whole . '.' . rtrim(str_pad((string) $fraction, 3, '0', STR_PAD_LEFT), '0');
    }

    public function format(Unit $unit): string
    {
        return $this->decimal() . ' ' . $unit->label();
    }
}
