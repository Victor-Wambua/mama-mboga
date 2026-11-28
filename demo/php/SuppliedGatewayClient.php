<?php

declare(strict_types=1);

namespace MamaMboga\Demo;

use MamaMboga\Application\Gateway\GatewayException;
use MamaMboga\Application\Gateway\GatewayPayment;
use MamaMboga\Application\Port\GatewayClient;

/**
 * The gateway as the browser demo's PHP sees it: not at all. PHP running in the browser has no network, so the page
 * itself talks to the mock gateway and hands PHP the payment it read. This client answers `getPayment()` with that
 * one payment, so the app's real reconcile step runs on it exactly as it does against a live gateway.
 * It cannot create a payment: the page does that.
 */
final class SuppliedGatewayClient implements GatewayClient
{
    public function __construct(private readonly GatewayPayment $payment)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createPayment(string $reference, int $amount, string $phone, string $description, string $idempotencyKey): GatewayPayment
    {
        throw new GatewayException(GatewayException::NOT_CONFIGURED, 'In the demo the browser, not PHP, sends the prompt.');
    }

    public function getPayment(string $id): GatewayPayment
    {
        if ($id !== $this->payment->id) {
            throw new GatewayException(GatewayException::NOT_FOUND, 'The page did not supply that payment.');
        }

        return $this->payment;
    }
}
