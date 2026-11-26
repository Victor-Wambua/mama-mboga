<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Order;

enum OrderStatus: string
{
    case Received = 'received';
    case Packing = 'packing';
    case Out = 'out';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(Fulfilment $fulfilment = Fulfilment::Delivery): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Packing => 'Packing',
            self::Out => $fulfilment === Fulfilment::Pickup ? 'Ready' : 'Out for delivery',
            self::Delivered => $fulfilment === Fulfilment::Pickup ? 'Collected' : 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Received => [self::Packing, self::Cancelled],
            self::Packing => [self::Out, self::Cancelled],
            self::Out => [self::Delivered, self::Cancelled],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }

    /** Still needs shopping, packing or delivering. */
    public function isOpen(): bool
    {
        return !$this->isFinal();
    }

    /** Still waiting to be bought at the market. */
    public function needsBuying(): bool
    {
        return $this === self::Received || $this === self::Packing;
    }
}
