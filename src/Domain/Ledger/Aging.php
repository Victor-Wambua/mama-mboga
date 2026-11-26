<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Ledger;

use MamaMboga\Domain\Money\Money;

/** What is still owed, split by how long ago it was charged. */
final readonly class Aging
{
    public function __construct(
        public Money $current,
        public Money $days8to14,
        public Money $days15to30,
        public Money $over30,
    ) {
    }

    public function total(): Money
    {
        return $this->current->add($this->days8to14)->add($this->days15to30)->add($this->over30);
    }

    /** Age in days of the oldest unpaid charge, or null when nothing is owed. */
    public function oldestBucket(): ?string
    {
        return match (true) {
            $this->over30->isPositive() => '30+',
            $this->days15to30->isPositive() => '15-30',
            $this->days8to14->isPositive() => '8-14',
            $this->current->isPositive() => '0-7',
            default => null,
        };
    }
}
