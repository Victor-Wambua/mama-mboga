<?php

declare(strict_types=1);

namespace MamaMboga\Application\Port;

use MamaMboga\Application\Gateway\GatewayException;
use MamaMboga\Application\Gateway\GatewayPayment;

/** The payments gateway, as the app needs it. Implementations: curl (production), a fake (tests), a supplied snapshot (browser demo). */
interface GatewayClient
{
    public function isConfigured(): bool;

    /**
     * Ask the gateway to send an M-Pesa prompt. The same idempotency key with the same details returns the same payment.
     *
     * @param string $reference our request id: letters, digits and . _ : - only, at most 64
     * @throws GatewayException
     */
    public function createPayment(string $reference, int $amount, string $phone, string $description, string $idempotencyKey): GatewayPayment;

    /** @throws GatewayException */
    public function getPayment(string $id): GatewayPayment;
}
