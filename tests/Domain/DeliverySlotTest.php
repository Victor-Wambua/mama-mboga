<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Domain;

use MamaMboga\Domain\Slot\DeliverySlot;
use PHPUnit\Framework\TestCase;

final class DeliverySlotTest extends TestCase
{
    private function now(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable("2026-03-12 {$time}", new \DateTimeZone('Africa/Nairobi'));
    }

    public function testLabels(): void
    {
        self::assertSame('10am–12pm', (new DeliverySlot(1, '2026-03-12', '10:00', '12:00', 5))->label());
        self::assertSame('4pm–6:30pm', (new DeliverySlot(1, '2026-03-12', '16:00', '18:30', 5))->label());
        self::assertSame('12pm–2pm', (new DeliverySlot(1, '2026-03-12', '12:00', '14:00', 5))->label());
    }

    public function testCapacity(): void
    {
        $slot = new DeliverySlot(1, '2026-03-12', '16:00', '18:00', 3);

        self::assertSame(3, $slot->remaining(0));
        self::assertSame(1, $slot->remaining(2));
        self::assertSame(0, $slot->remaining(3));
        self::assertSame(0, $slot->remaining(9));
        self::assertTrue($slot->isFull(3));
        self::assertFalse($slot->isFull(2));
    }

    public function testBookableWhileRoomAndBeforeCutoff(): void
    {
        $slot = new DeliverySlot(1, '2026-03-12', '16:00', '18:00', 3);

        self::assertTrue($slot->isBookable(2, $this->now('07:30')));
        self::assertTrue($slot->isBookable(0, $this->now('15:29')));
    }

    public function testClosesThirtyMinutesBeforeStart(): void
    {
        $slot = new DeliverySlot(1, '2026-03-12', '16:00', '18:00', 3);

        self::assertFalse($slot->isBookable(0, $this->now('15:30')));
        self::assertFalse($slot->isBookable(0, $this->now('17:00')));
    }

    public function testFullSlotIsNotBookable(): void
    {
        self::assertFalse((new DeliverySlot(1, '2026-03-12', '16:00', '18:00', 2))->isBookable(2, $this->now('07:30')));
    }

    public function testZeroCapacityMeansClosed(): void
    {
        self::assertFalse((new DeliverySlot(1, '2026-03-12', '16:00', '18:00', 0))->isBookable(0, $this->now('07:30')));
    }
}
