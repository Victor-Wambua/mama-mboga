<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Parsing;

enum LineIssue: string
{
    case QuantityAssumed = 'quantity_assumed';
    case UnitMismatch = 'unit_mismatch';
    case FractionalNotAllowed = 'fractional_not_allowed';
    case LargeQuantity = 'large_quantity';
    case ZeroQuantity = 'zero_quantity';
    case UnreadableQuantity = 'unreadable_quantity';
    case FuzzyMatch = 'fuzzy_match';
    case MergedDuplicates = 'merged_duplicates';

    /** Blocking issues mean a human must look before the order is confirmed. */
    public function isBlocking(): bool
    {
        return match ($this) {
            self::FuzzyMatch, self::MergedDuplicates => false,
            default => true,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::QuantityAssumed => 'No quantity found, assumed 1.',
            self::UnitMismatch => 'The unit written is not how this item is sold today.',
            self::FractionalNotAllowed => 'This amount does not fit how the item is sold.',
            self::LargeQuantity => 'That is a very large amount, check it.',
            self::ZeroQuantity => 'The quantity is zero.',
            self::UnreadableQuantity => 'The quantity could not be read.',
            self::FuzzyMatch => 'Matched despite a spelling difference.',
            self::MergedDuplicates => 'The same item was listed more than once and was added together.',
        };
    }
}
