<?php

declare(strict_types=1);

namespace MamaMboga\Application\Gateway;

/** The gateway could not do what was asked. `retryable` says whether asking again, unchanged, is safe and sensible. */
final class GatewayException extends \RuntimeException
{
    public const UNREACHABLE = 'unreachable';
    public const RATE_LIMITED = 'rate_limited';
    public const REJECTED = 'rejected';
    public const NOT_FOUND = 'not_found';
    public const NOT_CONFIGURED = 'not_configured';
    public const SERVER = 'server';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return in_array($this->kind, [self::UNREACHABLE, self::RATE_LIMITED, self::SERVER, self::NOT_CONFIGURED], true);
    }
}
