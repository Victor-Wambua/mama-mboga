<?php

declare(strict_types=1);

namespace MamaMboga\Application\Gateway;

/** One payment as the gateway reports it (`GET /v1/payments/:id`). Phone numbers arrive masked. */
final readonly class GatewayPayment
{
    /** @param list<string> $flags */
    public function __construct(
        public string $id,
        public string $reference,
        public int $amount,
        public GatewayStatus $status,
        public ?string $receipt = null,
        public array $flags = [],
        public ?int $resultCode = null,
        public string $resultDesc = '',
    ) {
    }

    /**
     * @param array<mixed> $data the `data` object of a gateway response
     * @throws \InvalidArgumentException when the shape is not a payment
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $reference = $data['reference'] ?? null;
        $amount = $data['amount'] ?? null;
        $status = is_string($data['status'] ?? null) ? GatewayStatus::tryFrom($data['status']) : null;
        if (!is_string($id) || $id === '' || !is_string($reference) || !is_int($amount) || $status === null) {
            throw new \InvalidArgumentException('That is not a gateway payment.');
        }
        $flags = is_array($data['flags'] ?? null) ? array_values(array_filter($data['flags'], 'is_string')) : [];
        $receipt = $data['mpesa_receipt'] ?? null;

        return new self(
            $id,
            $reference,
            $amount,
            $status,
            is_string($receipt) && $receipt !== '' ? $receipt : null,
            $flags,
            is_int($data['result_code'] ?? null) ? $data['result_code'] : null,
            is_string($data['result_desc'] ?? null) ? $data['result_desc'] : '',
        );
    }
}
