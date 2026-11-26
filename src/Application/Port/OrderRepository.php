<?php

declare(strict_types=1);

namespace MamaMboga\Application\Port;

use MamaMboga\Domain\Order\Order;

interface OrderRepository
{
    public function insert(Order $order): Order;

    public function find(int $id): ?Order;

    public function findByReference(string $reference): ?Order;

    /** @return list<Order> oldest first */
    public function forDate(string $date): array;

    /** @return list<Order> newest first */
    public function forCustomer(int $customerId, int $limit = 20): array;

    public function updateStatus(Order $order): void;

    /** Non-cancelled orders in a slot. */
    public function bookedInSlot(int $slotId): int;

    /** @return array<int, int> slot id => booked orders */
    public function bookedBySlot(string $date): array;

    /** Deni orders not yet delivered or cancelled: promised, not yet charged. */
    public function pendingDeniTotal(int $customerId): int;

    public function referenceExists(string $reference): bool;
}
