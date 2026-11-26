<?php

declare(strict_types=1);

namespace MamaMboga\Application\Messaging;

use MamaMboga\Domain\Order\Fulfilment;
use MamaMboga\Domain\Order\Order;

final class OrderMessage
{
    /** The text a customer sends to the shop after placing an order. */
    public static function forShop(Order $order, string $shopName, ?string $slotLabel): string
    {
        $lines = ["Habari {$shopName}! Oda yangu / my order {$order->reference}:", ''];
        foreach ($order->lines as $line) {
            $lines[] = sprintf(
                '• %s (%s) × %s = %s',
                $line->nameSw,
                $line->nameEn,
                $line->quantity->format($line->unit),
                $line->total()->format(),
            );
        }
        $lines[] = '';
        $lines[] = 'Jumla / Total: ' . $order->total()->format();
        if ($order->fulfilment === Fulfilment::Delivery) {
            $lines[] = 'Delivery: ' . ($slotLabel ?? 'today') . ', ' . $order->address;
        } else {
            $lines[] = 'Pickup at the shop';
        }
        if ($order->isDeni()) {
            $lines[] = 'Malipo: deni';
        }
        $lines[] = "{$order->customerName}, {$order->customerPhone->local()}";
        $lines[] = 'Asante!';

        return implode("\n", $lines);
    }

    /** A short status note the owner can send to the customer. */
    public static function statusUpdate(Order $order, string $shopName): string
    {
        $what = match ($order->status->value) {
            'packing' => 'tunapakia / we are packing your order',
            'out' => $order->fulfilment === Fulfilment::Pickup ? 'iko tayari / it is ready for pickup' : 'iko njiani / it is on its way',
            'delivered' => 'imewasilishwa / delivered. Asante!',
            'cancelled' => 'imeghairiwa / has been cancelled. Pole, call us for help.',
            default => 'tumeipokea / we have received your order',
        };

        return "Habari {$order->customerName}, order {$order->reference} {$what}. {$shopName}";
    }
}
