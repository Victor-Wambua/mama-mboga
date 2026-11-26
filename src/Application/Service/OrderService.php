<?php

declare(strict_types=1);

namespace MamaMboga\Application\Service;

use MamaMboga\Application\Command\PlaceOrder;
use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Port\CustomerRepository;
use MamaMboga\Application\Port\OrderRepository;
use MamaMboga\Application\Port\PriceBoardRepository;
use MamaMboga\Application\Port\SlotRepository;
use MamaMboga\Application\Port\Transaction;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Catalogue\StockPrice;
use MamaMboga\Domain\Customer\Customer;
use MamaMboga\Domain\Ledger\LedgerViolation;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\PhoneNumber;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Order\Fulfilment;
use MamaMboga\Domain\Order\Order;
use MamaMboga\Domain\Order\OrderLine;
use MamaMboga\Domain\Order\OrderStatus;
use MamaMboga\Domain\Order\PaymentMode;

final class OrderService
{
    public function __construct(
        private readonly PriceBoardRepository $board,
        private readonly SlotRepository $slots,
        private readonly OrderRepository $orders,
        private readonly CustomerRepository $customers,
        private readonly LedgerService $ledger,
        private readonly Transaction $tx,
        private readonly Clock $clock,
        private readonly ReferenceGenerator $references,
        private readonly SlotPlanner $planner,
    ) {
    }

    /**
     * Validate and place an order against today's board. Stock, the slot's
     * capacity and the customer's credit are checked inside one write
     * transaction, so two customers cannot both take the last bunch or the
     * last place in a slot.
     *
     * @throws ValidationException
     */
    public function place(PlaceOrder $cmd): Order
    {
        $errors = [];

        $name = trim($cmd->name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors['name'] = 'Enter your name (2 to 80 letters).';
        }
        $phone = PhoneNumber::tryParse($cmd->phone);
        if ($phone === null) {
            $errors['phone'] = 'Enter a Kenyan mobile number such as 0700 000 001.';
        }
        $fulfilment = Fulfilment::tryFrom($cmd->fulfilment);
        if ($fulfilment === null) {
            $errors['fulfilment'] = 'Choose delivery or pickup.';
        }
        $address = trim($cmd->address);
        if ($fulfilment === Fulfilment::Delivery && (mb_strlen($address) < 3 || mb_strlen($address) > 200)) {
            $errors['address'] = 'Tell us where to deliver (an estate, road or landmark).';
        }
        if ($fulfilment === Fulfilment::Delivery && $cmd->slotId === null) {
            $errors['slot'] = 'Choose a delivery time.';
        }
        if (mb_strlen($cmd->note) > 200) {
            $errors['note'] = 'Keep the note under 200 letters.';
        }

        $wanted = [];
        foreach ($cmd->quantities as $entryId => $raw) {
            $raw = trim($raw);
            if ($raw === '' || $raw === '0') {
                continue;
            }
            try {
                $qty = Quantity::fromDecimal($raw);
            } catch (\InvalidArgumentException) {
                $errors['items'] = 'One of the quantities is not a number.';
                continue;
            }
            if ($qty->isPositive()) {
                $wanted[(int) $entryId] = $qty;
            }
        }
        if ($wanted === [] && !isset($errors['items'])) {
            $errors['items'] = 'Add at least one item to your basket.';
        }
        if ($errors !== [] || $phone === null || $fulfilment === null) {
            throw new ValidationException($errors);
        }

        return $this->tx->run(function () use ($cmd, $name, $phone, $fulfilment, $address, $wanted): Order {
            $now = $this->clock->now();
            $today = $now->format('Y-m-d');
            $errors = [];

            $stock = [];
            foreach ($this->board->board($today) as $row) {
                if ($row->entryId !== null) {
                    $stock[$row->entryId] = $row;
                }
            }

            $lines = [];
            foreach ($wanted as $entryId => $qty) {
                $entry = $stock[$entryId] ?? null;
                if ($entry === null) {
                    $errors['items'] = 'An item you picked is no longer on today\'s list.';
                    continue;
                }
                $problem = $this->lineProblem($entry, $qty);
                if ($problem !== null) {
                    $errors['items'] = $problem;
                    continue;
                }
                $lines[$entryId] = $entry->lineFor($qty);
            }

            $slotId = null;
            if ($fulfilment === Fulfilment::Delivery) {
                $slot = $cmd->slotId === null ? null : $this->slots->find($cmd->slotId);
                if ($slot === null || $slot->date !== $today) {
                    $errors['slot'] = 'That delivery time is not available.';
                } elseif (!$slot->isBookable($this->orders->bookedInSlot((int) $slot->id), $now)) {
                    $errors['slot'] = "The {$slot->label()} slot is full or has started. Please pick another.";
                } else {
                    $slotId = $slot->id;
                }
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }

            $total = array_reduce($lines, static fn (Money $sum, OrderLine $l): Money => $sum->add($l->total()), Money::zero());
            $customer = $this->customers->findByPhone($phone)
                ?? $this->customers->save(new Customer(null, $name, $phone, Money::zero()));

            $mode = PaymentMode::PayOnDelivery;
            if ($cmd->onDeni) {
                if (!$customer->hasCredit()) {
                    throw ValidationException::field('payment', 'Deni is for regular customers. Ask the shop to set up your credit.');
                }
                $room = $this->ledger->ledgerFor((int) $customer->id)->available()->amount - $this->orders->pendingDeniTotal((int) $customer->id);
                if ($room < $total->amount) {
                    throw ValidationException::field('payment', 'This order is over your deni limit. Pay on delivery, or settle some of your balance first.');
                }
                $mode = PaymentMode::Deni;
            }

            foreach ($lines as $entryId => $line) {
                if (!$this->board->adjustAvailable($entryId, -$line->quantity->milli)) {
                    throw ValidationException::field('items', "Sorry, {$line->nameEn} just ran out. Please adjust your basket.");
                }
            }

            $order = new Order(
                null,
                $this->references->next(),
                $today,
                $customer->id,
                $name,
                $phone,
                $fulfilment,
                $slotId,
                $fulfilment === Fulfilment::Delivery ? $address : '',
                trim($cmd->note),
                OrderStatus::Received,
                $mode,
                null,
                array_values($lines),
                $cmd->source === 'whatsapp' ? 'whatsapp' : 'web',
                $now,
            );

            return $this->orders->insert($order);
        });
    }

    private function lineProblem(StockPrice $entry, Quantity $qty): ?string
    {
        if (!$entry->validAmount($qty)) {
            return $entry->unit->increment() === 1000
                ? "{$entry->nameEn} is sold in whole {$entry->unit->label()}s."
                : "{$entry->nameEn} is sold in steps of " . Quantity::milli($entry->unit->increment())->decimal() . ' kg.';
        }
        if (!$entry->available->atLeast($qty)) {
            return $entry->isSoldOut()
                ? "{$entry->nameEn} is sold out today."
                : "Only {$entry->available->format($entry->unit)} of {$entry->nameEn} left.";
        }

        return null;
    }

    /**
     * Move an order along its status flow. Delivery settles it: on deni it is
     * charged to the customer's ledger, otherwise the owner says how it was paid.
     *
     * @throws ValidationException
     */
    public function changeStatus(int $orderId, OrderStatus $to, string $settledVia = 'cash'): Order
    {
        return $this->tx->run(function () use ($orderId, $to, $settledVia): Order {
            $order = $this->orders->find($orderId) ?? throw ValidationException::field('order', 'Order not found.');

            try {
                $next = $order->transitionTo($to);
            } catch (\DomainException $e) {
                throw ValidationException::field('status', $e->getMessage());
            }

            if ($to === OrderStatus::Delivered) {
                if ($order->isDeni() && $order->customerId !== null) {
                    try {
                        $this->ledger->charge($order->customerId, $order->total(), $order->id, "Order {$order->reference}");
                    } catch (LedgerViolation $e) {
                        throw ValidationException::field('status', $e->getMessage());
                    }
                    $next = $next->settledVia('deni');
                } else {
                    $next = $next->settledVia($settledVia === 'mpesa' ? 'mpesa' : 'cash');
                }
            }

            if ($to === OrderStatus::Cancelled) {
                foreach ($order->lines as $line) {
                    $this->board->adjustAvailableByItem($order->boardDate, $line->itemId, $line->quantity->milli);
                }
            }

            $this->orders->updateStatus($next);

            return $next;
        });
    }

    /** @return list<Order> */
    public function forDay(string $date): array
    {
        return $this->orders->forDate($date);
    }

    public function find(int $id): ?Order
    {
        return $this->orders->find($id);
    }

    public function findByReference(string $reference): ?Order
    {
        return $this->orders->findByReference($reference);
    }

    /** @return array<int, int> slot id => booked */
    public function slotLoad(string $date): array
    {
        $this->planner->ensure($date);

        return $this->orders->bookedBySlot($date);
    }
}
