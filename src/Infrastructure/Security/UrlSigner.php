<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Security;

/** HMAC-SHA256 signatures for links that open without a login (statements, order confirmations). */
final class UrlSigner
{
    public function __construct(private readonly string $key)
    {
    }

    public function sign(string $payload): string
    {
        $mac = hash_hmac('sha256', $payload, $this->key, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    public function verify(string $payload, string $signature): bool
    {
        return hash_equals($this->sign($payload), $signature);
    }
}
