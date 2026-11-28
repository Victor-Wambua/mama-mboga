<?php

declare(strict_types=1);

namespace MamaMboga\Application\Port;

use MamaMboga\Application\Collection\PaymentRequest;

interface PaymentRequestRepository
{
    /** Inserts and returns the request with its id. */
    public function add(PaymentRequest $request): PaymentRequest;

    /** Writes the current state of an existing request. There is no delete. */
    public function save(PaymentRequest $request): void;

    public function find(int $id): ?PaymentRequest;

    public function findByGatewayPaymentId(string $paymentId): ?PaymentRequest;

    public function referenceExists(string $reference): bool;

    /** @return list<PaymentRequest> newest first */
    public function forCustomer(int $customerId): array;

    /**
     * Requests worth asking the gateway about again: still pending (sent or not), or timed out since
     * `$lateSince` (a late success can still turn up). Recorded requests and ones waiting on the owner are not.
     *
     * @return list<PaymentRequest> oldest first
     */
    public function open(\DateTimeImmutable $lateSince): array;

    /** @return list<PaymentRequest> waiting for the owner and not yet looked at, oldest first */
    public function needingOwner(): array;
}
