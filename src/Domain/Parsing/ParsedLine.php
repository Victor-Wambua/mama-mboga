<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Parsing;

use MamaMboga\Domain\Catalogue\StockPrice;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;

/** One thing the customer asked for, as far as the parser could tell. */
final readonly class ParsedLine
{
    /**
     * @param list<StockPrice> $alternatives other items that fit equally well
     * @param list<LineIssue>  $issues
     */
    public function __construct(
        public string $raw,
        public string $fragment,
        public LineStatus $status,
        public ?StockPrice $item,
        public array $alternatives,
        public ?Quantity $quantity,
        public ?Unit $writtenUnit,
        public array $issues,
        public float $confidence,
    ) {
    }

    public function needsReview(): bool
    {
        if ($this->status !== LineStatus::Matched) {
            return true;
        }
        foreach ($this->issues as $issue) {
            if ($issue->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    public function has(LineIssue $issue): bool
    {
        return in_array($issue, $this->issues, true);
    }
}
