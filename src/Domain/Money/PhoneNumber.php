<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Money;

/** A Kenyan mobile number, stored as 2547XXXXXXXX / 2541XXXXXXXX. */
final readonly class PhoneNumber
{
    private function __construct(public string $e164)
    {
    }

    public static function parse(string $input): self
    {
        $digits = preg_replace('/[^\d]/', '', $input) ?? '';

        if (str_starts_with($digits, '254')) {
            $national = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } else {
            $national = $digits;
        }

        if (preg_match('/^[17]\d{8}$/', $national) !== 1) {
            throw new \InvalidArgumentException('Enter a Kenyan mobile number such as 0700 000 001.');
        }

        return new self('254' . $national);
    }

    public static function tryParse(string $input): ?self
    {
        try {
            return self::parse($input);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** 0700 000 001 */
    public function local(): string
    {
        $national = '0' . substr($this->e164, 3);

        return substr($national, 0, 4) . ' ' . substr($national, 4, 3) . ' ' . substr($national, 7);
    }

    public function equals(self $other): bool
    {
        return $this->e164 === $other->e164;
    }
}
