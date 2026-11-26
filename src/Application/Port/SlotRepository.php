<?php

declare(strict_types=1);

namespace MamaMboga\Application\Port;

use MamaMboga\Domain\Slot\DeliverySlot;

interface SlotRepository
{
    /** @return list<DeliverySlot> */
    public function forDate(string $date): array;

    public function find(int $id): ?DeliverySlot;

    /** @param list<array{0: string, 1: string, 2: int}> $defaults [start, end, capacity] */
    public function ensureDefaults(string $date, array $defaults): void;

    public function setCapacity(int $id, int $capacity): void;
}
