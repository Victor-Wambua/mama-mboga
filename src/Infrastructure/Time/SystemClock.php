<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Time;

use MamaMboga\Application\Port\Clock;

final class SystemClock implements Clock
{
    private readonly \DateTimeZone $zone;

    public function __construct(string $timezone = 'Africa/Nairobi')
    {
        $this->zone = new \DateTimeZone($timezone);
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->zone);
    }
}
