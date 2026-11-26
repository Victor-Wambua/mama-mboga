<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Parsing;

use MamaMboga\Domain\Money\PhoneNumber;

final readonly class ParsedOrder
{
    /**
     * @param list<ParsedLine> $lines
     * @param list<string>     $ignored text that looked like chatter, not an order
     */
    public function __construct(
        public array $lines,
        public array $ignored,
        public ?PhoneNumber $phone,
        public ?string $timeHint,
        public ?string $addressHint = null,
    ) {
    }

    public function needsReview(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->needsReview()) {
                return true;
            }
        }

        return $this->lines === [];
    }

    /** @return list<ParsedLine> */
    public function flagged(): array
    {
        return array_values(array_filter($this->lines, static fn (ParsedLine $l): bool => $l->needsReview()));
    }
}
