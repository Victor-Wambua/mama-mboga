<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Order;

enum PaymentMode: string
{
    case PayOnDelivery = 'pay_on_delivery';
    case Deni = 'deni';
}
