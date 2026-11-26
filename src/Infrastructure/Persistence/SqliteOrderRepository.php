<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Persistence;

use MamaMboga\Application\Port\OrderRepository;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\PhoneNumber;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;
use MamaMboga\Domain\Order\Fulfilment;
use MamaMboga\Domain\Order\Order;
use MamaMboga\Domain\Order\OrderLine;
use MamaMboga\Domain\Order\OrderStatus;
use MamaMboga\Domain\Order\PaymentMode;

final class SqliteOrderRepository implements OrderRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function insert(Order $order): Order
    {
        $this->db->execute(
            'INSERT INTO orders (reference, board_date, customer_id, customer_name, customer_phone, fulfilment, slot_id, address, note,
                                 status, payment_mode, settled_via, total_kes, source, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $order->reference, $order->boardDate, $order->customerId, $order->customerName, $order->customerPhone->e164,
                $order->fulfilment->value, $order->slotId, $order->address, $order->note, $order->status->value,
                $order->paymentMode->value, $order->settledVia, $order->total()->amount, $order->source, $order->createdAt->format('c'),
            ],
        );
        $id = $this->db->lastId();
        foreach ($order->lines as $line) {
            $this->db->execute(
                'INSERT INTO order_lines (order_id, item_id, name_en, name_sw, unit, quantity_milli, unit_price_kes) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $line->itemId, $line->nameEn, $line->nameSw, $line->unit->value, $line->quantity->milli, $line->unitPrice->amount],
            );
        }

        return $order->withId($id);
    }

    public function find(int $id): ?Order
    {
        $row = $this->db->one('SELECT * FROM orders WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row, $this->lines([$id])[$id] ?? []);
    }

    public function findByReference(string $reference): ?Order
    {
        $row = $this->db->one('SELECT * FROM orders WHERE reference = ?', [$reference]);

        return $row === null ? null : $this->hydrate($row, $this->lines([(int) $row['id']])[(int) $row['id']] ?? []);
    }

    public function forDate(string $date): array
    {
        return $this->many($this->db->all('SELECT * FROM orders WHERE board_date = ? ORDER BY id', [$date]));
    }

    public function forCustomer(int $customerId, int $limit = 20): array
    {
        return $this->many($this->db->all('SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$customerId]));
    }

    public function updateStatus(Order $order): void
    {
        $this->db->execute('UPDATE orders SET status = ?, settled_via = ? WHERE id = ?', [$order->status->value, $order->settledVia, $order->id]);
    }

    public function bookedInSlot(int $slotId): int
    {
        return $this->db->scalar("SELECT COUNT(*) FROM orders WHERE slot_id = ? AND status <> 'cancelled'", [$slotId]);
    }

    public function bookedBySlot(string $date): array
    {
        $rows = $this->db->all(
            "SELECT o.slot_id, COUNT(*) AS n FROM orders o JOIN delivery_slots s ON s.id = o.slot_id
             WHERE s.slot_date = ? AND o.status <> 'cancelled' GROUP BY o.slot_id",
            [$date],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['slot_id']] = (int) $row['n'];
        }

        return $out;
    }

    public function pendingDeniTotal(int $customerId): int
    {
        return $this->db->scalar(
            "SELECT COALESCE(SUM(total_kes), 0) FROM orders WHERE customer_id = ? AND payment_mode = 'deni' AND status IN ('received', 'packing', 'out')",
            [$customerId],
        );
    }

    public function referenceExists(string $reference): bool
    {
        return $this->db->scalar('SELECT COUNT(*) FROM orders WHERE reference = ?', [$reference]) > 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Order>
     */
    private function many(array $rows): array
    {
        $lines = $this->lines(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return array_map(fn (array $r): Order => $this->hydrate($r, $lines[(int) $r['id']] ?? []), $rows);
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, list<OrderLine>>
     */
    private function lines(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($orderIds), '?'));
        $out = [];
        foreach ($this->db->all("SELECT * FROM order_lines WHERE order_id IN ({$marks}) ORDER BY id", $orderIds) as $r) {
            $out[(int) $r['order_id']][] = new OrderLine(
                (int) $r['item_id'],
                (string) $r['name_en'],
                (string) $r['name_sw'],
                Unit::from((string) $r['unit']),
                Quantity::milli((int) $r['quantity_milli']),
                Money::kes((int) $r['unit_price_kes']),
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @param list<OrderLine>      $lines
     */
    private function hydrate(array $r, array $lines): Order
    {
        return new Order(
            (int) $r['id'],
            (string) $r['reference'],
            (string) $r['board_date'],
            $r['customer_id'] === null ? null : (int) $r['customer_id'],
            (string) $r['customer_name'],
            PhoneNumber::parse((string) $r['customer_phone']),
            Fulfilment::from((string) $r['fulfilment']),
            $r['slot_id'] === null ? null : (int) $r['slot_id'],
            (string) $r['address'],
            (string) $r['note'],
            OrderStatus::from((string) $r['status']),
            PaymentMode::from((string) $r['payment_mode']),
            $r['settled_via'] === null ? null : (string) $r['settled_via'],
            $lines,
            (string) $r['source'],
            new \DateTimeImmutable((string) $r['created_at']),
        );
    }
}
