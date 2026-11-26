<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Persistence;

use MamaMboga\Application\Port\PriceBoardRepository;
use MamaMboga\Domain\Catalogue\Item;
use MamaMboga\Domain\Catalogue\StockPrice;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;

final class SqlitePriceBoardRepository implements PriceBoardRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function items(): array
    {
        return array_map($this->hydrateItem(...), $this->db->all('SELECT * FROM items WHERE active = 1 ORDER BY name_en'));
    }

    public function item(int $id): ?Item
    {
        $row = $this->db->one('SELECT * FROM items WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrateItem($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrateItem(array $row): Item
    {
        return new Item(
            (int) $row['id'],
            (string) $row['name_en'],
            (string) $row['name_sw'],
            Item::splitAliases((string) $row['aliases']),
            Unit::from((string) $row['default_unit']),
            (bool) $row['active'],
        );
    }

    public function saveItem(Item $item): Item
    {
        $aliases = implode(', ', $item->aliases);
        if ($item->id === null) {
            $this->db->execute(
                'INSERT INTO items (name_en, name_sw, aliases, default_unit) VALUES (?, ?, ?, ?)',
                [$item->nameEn, $item->nameSw, $aliases, $item->defaultUnit->value],
            );

            return new Item($this->db->lastId(), $item->nameEn, $item->nameSw, $item->aliases, $item->defaultUnit);
        }
        $this->db->execute(
            'UPDATE items SET name_en = ?, name_sw = ?, aliases = ?, default_unit = ? WHERE id = ?',
            [$item->nameEn, $item->nameSw, $aliases, $item->defaultUnit->value, $item->id],
        );

        return $item;
    }

    public function board(string $date): array
    {
        $rows = $this->db->all($this->select() . ' WHERE b.board_date = ? ORDER BY i.name_en', [$date]);

        return array_map($this->stockPrice(...), $rows);
    }

    public function entry(int $entryId): ?StockPrice
    {
        $row = $this->db->one($this->select() . ' WHERE b.id = ?', [$entryId]);

        return $row === null ? null : $this->stockPrice($row);
    }

    public function latestBoardDateBefore(string $date): ?string
    {
        $row = $this->db->one('SELECT MAX(board_date) AS d FROM board_entries WHERE board_date < ?', [$date]);

        return isset($row['d']) ? (string) $row['d'] : null;
    }

    public function saveEntry(string $date, int $itemId, Unit $unit, Money $price, Quantity $available, bool $special, string $specialNote): void
    {
        $this->db->execute(
            'INSERT INTO board_entries (board_date, item_id, unit, price_kes, available_milli, special, special_note)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (board_date, item_id) DO UPDATE SET
                unit = excluded.unit, price_kes = excluded.price_kes, available_milli = excluded.available_milli,
                special = excluded.special, special_note = excluded.special_note',
            [$date, $itemId, $unit->value, $price->amount, $available->milli, (int) $special, $specialNote],
        );
    }

    public function removeEntry(int $entryId): void
    {
        $this->db->execute('DELETE FROM board_entries WHERE id = ?', [$entryId]);
    }

    public function adjustAvailable(int $entryId, int $deltaMilli): bool
    {
        return $this->db->execute(
            'UPDATE board_entries SET available_milli = available_milli + ? WHERE id = ? AND available_milli + ? >= 0',
            [$deltaMilli, $entryId, $deltaMilli],
        ) === 1;
    }

    public function adjustAvailableByItem(string $date, int $itemId, int $deltaMilli): void
    {
        $this->db->execute(
            'UPDATE board_entries SET available_milli = MAX(0, available_milli + ?) WHERE board_date = ? AND item_id = ?',
            [$deltaMilli, $date, $itemId],
        );
    }

    private function select(): string
    {
        return 'SELECT b.id AS entry_id, b.item_id, b.unit, b.price_kes, b.available_milli, b.special, b.special_note,
                       i.name_en, i.name_sw, i.aliases
                FROM board_entries b JOIN items i ON i.id = b.item_id';
    }

    /** @param array<string, mixed> $r */
    private function stockPrice(array $r): StockPrice
    {
        return new StockPrice(
            (int) $r['entry_id'],
            (int) $r['item_id'],
            (string) $r['name_en'],
            (string) $r['name_sw'],
            Item::splitAliases((string) $r['aliases']),
            Unit::from((string) $r['unit']),
            Money::kes((int) $r['price_kes']),
            Quantity::milli((int) $r['available_milli']),
            (bool) $r['special'],
            (string) $r['special_note'],
        );
    }
}
