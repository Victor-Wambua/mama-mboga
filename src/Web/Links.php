<?php

declare(strict_types=1);

namespace MamaMboga\Web;

use MamaMboga\Infrastructure\Config\Config;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Security\UrlSigner;

/** Signed, login-free URLs: order confirmations and customer statements. */
final class Links
{
    public const STATEMENT_DAYS = 30;

    public function __construct(private readonly UrlSigner $signer, private readonly Config $config)
    {
    }

    public function orderPath(string $reference): string
    {
        return '/order/' . $reference . '/' . $this->signer->sign('order:' . $reference);
    }

    public function orderSignatureValid(string $reference, string $signature): bool
    {
        return $this->signer->verify('order:' . $reference, $signature);
    }

    public function statementPath(int $customerId, int $nowTimestamp, int $days = self::STATEMENT_DAYS): string
    {
        $expires = $nowTimestamp + $days * 86400;

        return "/s/{$customerId}/{$expires}/" . $this->signer->sign("statement:{$customerId}:{$expires}");
    }

    public function statementValid(int $customerId, int $expires, string $signature, int $nowTimestamp): bool
    {
        return $expires >= $nowTimestamp && $this->signer->verify("statement:{$customerId}:{$expires}", $signature);
    }

    /** Absolute URL for sharing in messages. */
    public function absolute(Request $request, string $path): string
    {
        return ($this->config->appUrl !== '' ? $this->config->appUrl : $request->baseUrl()) . $path;
    }
}
