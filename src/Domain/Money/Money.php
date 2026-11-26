<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Money;

/**
 * Kenyan shillings as a whole-number amount. There is no float anywhere in
 * the money path: line totals are computed with integer arithmetic and
 * rounded half-up to the nearest shilling.
 */
final readonly class Money implements \Stringable
{
    private function __construct(public int $amount)
    {
    }

    public static function kes(int $amount): self
    {
        return new self($amount);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->amount + $other->amount);
    }

    public function subtract(self $other): self
    {
        return new self($this->amount - $other->amount);
    }

    /** Price per unit multiplied by a quantity expressed in thousandths. */
    public function timesMilli(int $milli): self
    {
        if ($milli < 0) {
            throw new \InvalidArgumentException('Quantity cannot be negative.');
        }

        $product = $this->amount * $milli;
        $rounded = intdiv(abs($product) + 500, 1000);

        return new self($product < 0 ? -$rounded : $rounded);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        return $this->amount > $other->amount;
    }

    public function lessThan(self $other): bool
    {
        return $this->amount < $other->amount;
    }

    public function min(self $other): self
    {
        return $this->amount <= $other->amount ? $this : $other;
    }

    public function format(): string
    {
        $sign = $this->amount < 0 ? '-' : '';

        return $sign . 'KSh ' . number_format(abs($this->amount), 0, '.', ',');
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
