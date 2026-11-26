<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Order;

use MamaMboga\Domain\Ledger\EntryType;
use MamaMboga\Domain\Ledger\LedgerEntry;
use MamaMboga\Domain\Money\Money;

/** The day's money: what sold, how it was settled, and old debts collected. */
final readonly class DailyTakings
{
    public function __construct(
        public int $delivered,
        public int $cancelled,
        public int $open,
        public Money $sales,
        public Money $cashAtDoor,
        public Money $mpesaAtDoor,
        public Money $onDeni,
        public Money $debtCashCollected,
        public Money $debtMpesaCollected,
    ) {
    }

    public function cashInHand(): Money
    {
        return $this->cashAtDoor->add($this->debtCashCollected);
    }

    public function mpesaReceived(): Money
    {
        return $this->mpesaAtDoor->add($this->debtMpesaCollected);
    }

    public function totalReceived(): Money
    {
        return $this->cashInHand()->add($this->mpesaReceived());
    }

    /**
     * @param iterable<Order>       $orders  orders placed for the day
     * @param iterable<LedgerEntry> $entries ledger entries written that day
     */
    public static function summarise(iterable $orders, iterable $entries): self
    {
        $delivered = $cancelled = $open = 0;
        $sales = $cash = $mpesa = $deni = Money::zero();

        foreach ($orders as $order) {
            if ($order->status === OrderStatus::Cancelled) {
                $cancelled++;
            } elseif ($order->status === OrderStatus::Delivered) {
                $delivered++;
                $total = $order->total();
                $sales = $sales->add($total);
                match ($order->settledVia) {
                    'mpesa' => $mpesa = $mpesa->add($total),
                    'deni' => $deni = $deni->add($total),
                    default => $cash = $cash->add($total),
                };
            } else {
                $open++;
            }
        }

        $debtCash = $debtMpesa = Money::zero();
        foreach ($entries as $entry) {
            if ($entry->type !== EntryType::Payment) {
                continue;
            }
            if ($entry->method === 'mpesa') {
                $debtMpesa = $debtMpesa->add($entry->amount);
            } else {
                $debtCash = $debtCash->add($entry->amount);
            }
        }

        return new self($delivered, $cancelled, $open, $sales, $cash, $mpesa, $deni, $debtCash, $debtMpesa);
    }
}
