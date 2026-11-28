<?php

declare(strict_types=1);

namespace MamaMboga\Application\Gateway;

/** The gateway's public payment states. The current one always comes from `payment.status`. */
enum GatewayStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    /** Can still become `needs_review` (flag `late_success`) if a success arrives after the deadline. */
    case TimedOut = 'timed_out';
    case NeedsReview = 'needs_review';
}
