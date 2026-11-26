<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Support;

use MamaMboga\Application\Seed\DemoCatalogue;
use MamaMboga\Domain\Catalogue\StockPrice;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\Quantity;

final class DemoBoard
{
    /** @return list<StockPrice> */
    public static function stock(): array
    {
        $out = [];
        foreach (DemoCatalogue::items() as $i => $row) {
            $out[] = new StockPrice(
                $i + 1,
                $i + 1,
                $row['en'],
                $row['sw'],
                $row['aliases'],
                $row['unit'],
                Money::kes($row['price']),
                Quantity::units($row['qty']),
                isset($row['special']),
                $row['special'] ?? '',
            );
        }

        return $out;
    }

    /** @return array<string, int> item key => id */
    public static function ids(): array
    {
        $map = [];
        foreach (DemoCatalogue::items() as $i => $row) {
            $map[$row['key']] = $i + 1;
        }

        return $map;
    }

    public static function item(string $key): StockPrice
    {
        $id = self::ids()[$key] ?? throw new \InvalidArgumentException("No demo item {$key}");

        return self::stock()[$id - 1];
    }
}
