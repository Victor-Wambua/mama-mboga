<?php

declare(strict_types=1);

namespace MamaMboga\Demo;

use MamaMboga\Application\Port\Clock;

/**
 * The shop clock for the browser demo: today's real date in Nairobi, fixed at 06:30,
 * so every delivery slot of the day is still open whenever a visitor arrives.
 */
final class MorningClock implements Clock
{
    private readonly \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $realNow = null)
    {
        $zone = new \DateTimeZone('Africa/Nairobi');
        $this->now = ($realNow ?? new \DateTimeImmutable('now', $zone))->setTimezone($zone)->setTime(6, 30);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
