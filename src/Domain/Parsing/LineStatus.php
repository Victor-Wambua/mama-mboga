<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Parsing;

enum LineStatus: string
{
    /** One item, confidently. */
    case Matched = 'matched';
    /** One plausible item, but only a partial match: the owner should confirm. */
    case Suggested = 'suggested';
    /** Two or more items fit equally well. */
    case Ambiguous = 'ambiguous';
    /** Nothing on today's board fits. */
    case Unmatched = 'unmatched';
}
