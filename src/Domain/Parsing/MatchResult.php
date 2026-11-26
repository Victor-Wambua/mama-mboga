<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Parsing;

use MamaMboga\Domain\Catalogue\StockPrice;

final readonly class MatchResult
{
    /** @param list<StockPrice> $candidates */
    public function __construct(
        public LineStatus $status,
        public array $candidates,
        public float $score,
        public bool $fuzzy = false,
    ) {
    }

    public function item(): ?StockPrice
    {
        return $this->status === LineStatus::Matched || $this->status === LineStatus::Suggested
            ? ($this->candidates[0] ?? null)
            : null;
    }
}
