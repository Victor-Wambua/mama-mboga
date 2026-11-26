<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Persistence;

use MamaMboga\Application\Port\SlotRepository;
use MamaMboga\Domain\Slot\DeliverySlot;

final class SqliteSlotRepository implements SlotRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function forDate(string $date): array
    {
        return array_map($this->hydrate(...), $this->db->all('SELECT * FROM delivery_slots WHERE slot_date = ? ORDER BY starts_at', [$date]));
    }

    public function find(int $id): ?DeliverySlot
    {
        $row = $this->db->one('SELECT * FROM delivery_slots WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function ensureDefaults(string $date, array $defaults): void
    {
        foreach ($defaults as [$start, $end, $capacity]) {
            $this->db->execute(
                'INSERT OR IGNORE INTO delivery_slots (slot_date, starts_at, ends_at, capacity) VALUES (?, ?, ?, ?)',
                [$date, $start, $end, $capacity],
            );
        }
    }

    public function setCapacity(int $id, int $capacity): void
    {
        $this->db->execute('UPDATE delivery_slots SET capacity = ? WHERE id = ?', [$capacity, $id]);
    }

    /** @param array<string, mixed> $r */
    private function hydrate(array $r): DeliverySlot
    {
        return new DeliverySlot((int) $r['id'], (string) $r['slot_date'], (string) $r['starts_at'], (string) $r['ends_at'], (int) $r['capacity']);
    }
}
