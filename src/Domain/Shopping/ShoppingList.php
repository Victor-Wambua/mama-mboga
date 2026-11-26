<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Shopping;

use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Order\Order;

/** What to buy at the market: every open order's lines added up per item. */
final class ShoppingList
{
    /**
     * Only orders still waiting to be packed count; anything already out for
     * delivery was bought this morning, and cancelled orders never were.
     *
     * @param iterable<Order> $orders
     * @return list<ShoppingLine>
     */
    public static function fromOrders(iterable $orders): array
    {
        /** @var array<string, array{line: ShoppingLine, orders: array<int|string, true>}> $acc */
        $acc = [];

        foreach ($orders as $order) {
            if (!$order->status->needsBuying()) {
                continue;
            }
            foreach ($order->lines as $line) {
                $key = $line->itemId . ':' . $line->unit->value;
                $existing = $acc[$key]['line'] ?? null;
                $total = $existing->quantity ?? Quantity::zero();
                $seen = $acc[$key]['orders'] ?? [];
                $seen[$order->id ?? $order->reference] = true;

                $acc[$key] = [
                    'line' => new ShoppingLine(
                        $line->itemId,
                        $line->nameEn,
                        $line->nameSw,
                        $line->unit,
                        $total->add($line->quantity),
                        count($seen),
                    ),
                    'orders' => $seen,
                ];
            }
        }

        $lines = array_map(static fn (array $row): ShoppingLine => $row['line'], array_values($acc));
        usort($lines, static fn (ShoppingLine $a, ShoppingLine $b): int => [$a->nameEn, $a->unit->value] <=> [$b->nameEn, $b->unit->value]);

        return $lines;
    }
}
