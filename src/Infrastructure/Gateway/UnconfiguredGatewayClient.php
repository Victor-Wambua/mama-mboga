<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Gateway;

use MamaMboga\Application\Gateway\GatewayException;
use MamaMboga\Application\Gateway\GatewayPayment;
use MamaMboga\Application\Port\GatewayClient;

/** Stands in when GATEWAY_URL or GATEWAY_SECRET_KEY is not set, so the rest of the app still runs. */
final class UnconfiguredGatewayClient implements GatewayClient
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function createPayment(string $reference, int $amount, string $phone, string $description, string $idempotencyKey): GatewayPayment
    {
        throw new GatewayException(GatewayException::NOT_CONFIGURED, 'The payments gateway is not set up.');
    }

    public function getPayment(string $id): GatewayPayment
    {
        throw new GatewayException(GatewayException::NOT_CONFIGURED, 'The payments gateway is not set up.');
    }
}
