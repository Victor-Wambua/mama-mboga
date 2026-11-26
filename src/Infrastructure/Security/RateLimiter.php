<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Security;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Infrastructure\Persistence\Database;

/** Fixed-window counter in SQLite: simple, survives restarts, good enough for one shop. */
final class RateLimiter
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /** Record a hit and return how many there have been in the current window. */
    public function hit(string $bucket, int $windowSeconds): int
    {
        $now = $this->clock->now()->getTimestamp();
        $window = intdiv($now, $windowSeconds) * $windowSeconds;

        $this->db->execute(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
             ON CONFLICT (bucket, window_start) DO UPDATE SET hits = hits + 1',
            [$bucket, $window],
        );
        if (random_int(1, 50) === 1) {
            $this->db->execute('DELETE FROM rate_limits WHERE window_start < ?', [$now - 86400]);
        }

        return $this->db->scalar('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$bucket, $window]);
    }

    public function retryAfter(int $windowSeconds): int
    {
        $now = $this->clock->now()->getTimestamp();

        return $windowSeconds - ($now % $windowSeconds);
    }
}
