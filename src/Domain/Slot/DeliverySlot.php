<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Slot;

/** A delivery window on one day with a limit on how many orders fit in it. */
final readonly class DeliverySlot
{
    public function __construct(
        public ?int $id,
        public string $date,
        public string $startsAt,
        public string $endsAt,
        public int $capacity,
    ) {
    }

    public function label(): string
    {
        return self::clock($this->startsAt) . '–' . self::clock($this->endsAt);
    }

    public function start(\DateTimeZone $zone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->date . ' ' . $this->startsAt, $zone);
    }

    /** @param int $booked non-cancelled orders already in the slot */
    public function remaining(int $booked): int
    {
        return max(0, $this->capacity - $booked);
    }

    public function isFull(int $booked): bool
    {
        return $this->remaining($booked) === 0;
    }

    /**
     * A slot can be booked while it has room and starts at least
     * $leadMinutes from now.
     */
    public function isBookable(int $booked, \DateTimeImmutable $now, int $leadMinutes = 30): bool
    {
        if ($this->isFull($booked)) {
            return false;
        }

        $cutoff = $this->start($now->getTimezone())->modify("-{$leadMinutes} minutes");

        return $now < $cutoff;
    }

    private static function clock(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        $suffix = $h >= 12 ? 'pm' : 'am';
        $h12 = $h % 12 === 0 ? 12 : $h % 12;

        return $m === 0 ? "{$h12}{$suffix}" : sprintf('%d:%02d%s', $h12, $m, $suffix);
    }
}
