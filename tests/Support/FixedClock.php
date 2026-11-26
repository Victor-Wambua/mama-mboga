<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Support;

use MamaMboga\Application\Port\Clock;

final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $time = '2026-03-12 07:30:00')
    {
        $this->now = new \DateTimeImmutable($time, new \DateTimeZone('Africa/Nairobi'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string $time): void
    {
        $this->now = new \DateTimeImmutable($time, new \DateTimeZone('Africa/Nairobi'));
    }
}
