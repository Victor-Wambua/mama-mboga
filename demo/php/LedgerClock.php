<?php

declare(strict_types=1);

namespace MamaMboga\Demo;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Infrastructure\Persistence\Database;

/**
 * The clock the deni book uses in the demo: real time, but never earlier than the newest ledger row.
 * The sample data books some of today's charges at 18:30, and the ledger refuses back-dated entries,
 * so without this a payment made before 18:30 would be rejected for the wrong reason.
 */
final class LedgerClock implements Clock
{
    private readonly \DateTimeZone $zone;

    public function __construct(
        private readonly Database $db,
        private readonly ?\DateTimeImmutable $realNow = null,
    ) {
        $this->zone = new \DateTimeZone('Africa/Nairobi');
    }

    public function now(): \DateTimeImmutable
    {
        $now = ($this->realNow ?? new \DateTimeImmutable('now', $this->zone))->setTimezone($this->zone);
        $row = $this->db->one('SELECT occurred_at FROM ledger_entries ORDER BY occurred_at DESC LIMIT 1');
        if ($row === null) {
            return $now;
        }
        $latest = new \DateTimeImmutable((string) $row['occurred_at']);

        return $latest > $now ? $latest->setTimezone($this->zone) : $now;
    }
}
