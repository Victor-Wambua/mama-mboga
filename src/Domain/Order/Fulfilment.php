<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Order;

enum Fulfilment: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';
}
