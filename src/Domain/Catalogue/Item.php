<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Catalogue;

use MamaMboga\Domain\Money\Unit;

/** A thing the shop sells, with the names customers actually use for it. */
final readonly class Item
{
    /** @param list<string> $aliases */
    public function __construct(
        public ?int $id,
        public string $nameEn,
        public string $nameSw,
        public array $aliases,
        public Unit $defaultUnit,
        public bool $active = true,
    ) {
    }

    /** @return list<string> */
    public static function splitAliases(string $csv): array
    {
        $parts = array_map(trim(...), explode(',', $csv));

        return array_values(array_unique(array_filter($parts, static fn (string $p): bool => $p !== '')));
    }
}
