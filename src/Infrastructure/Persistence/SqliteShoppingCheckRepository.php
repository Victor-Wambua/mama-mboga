<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Persistence;

use MamaMboga\Application\Port\ShoppingCheckRepository;

final class SqliteShoppingCheckRepository implements ShoppingCheckRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function boughtOn(string $date): array
    {
        $out = [];
        foreach ($this->db->all('SELECT item_id, unit FROM shopping_checks WHERE board_date = ?', [$date]) as $row) {
            $out[$row['item_id'] . ':' . $row['unit']] = true;
        }

        return $out;
    }

    public function mark(string $date, int $itemId, string $unit, bool $bought): void
    {
        if ($bought) {
            $this->db->execute('INSERT OR IGNORE INTO shopping_checks (board_date, item_id, unit) VALUES (?, ?, ?)', [$date, $itemId, $unit]);

            return;
        }
        $this->db->execute('DELETE FROM shopping_checks WHERE board_date = ? AND item_id = ? AND unit = ?', [$date, $itemId, $unit]);
    }
}
