<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Customer;

use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\PhoneNumber;

final readonly class Customer
{
    public function __construct(
        public ?int $id,
        public string $name,
        public PhoneNumber $phone,
        public Money $creditLimit,
    ) {
    }

    public function hasCredit(): bool
    {
        return $this->creditLimit->isPositive();
    }
}
