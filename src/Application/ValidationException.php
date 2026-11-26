<?php

declare(strict_types=1);

namespace MamaMboga\Application;

/** Input the user can fix: carries one message per field. */
final class ValidationException extends \RuntimeException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => $message]);
    }
}
