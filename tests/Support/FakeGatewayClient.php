<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Support;

use MamaMboga\Application\Gateway\GatewayException;
use MamaMboga\Application\Gateway\GatewayPayment;
use MamaMboga\Application\Gateway\GatewayStatus;
use MamaMboga\Application\Port\GatewayClient;

/** An in-memory gateway for tests. It never touches the network, and the test decides what it reports. */
final class FakeGatewayClient implements GatewayClient
{
    /** @var array<string, GatewayPayment> by gateway payment id */
    public array $payments = [];
    /** @var array<string, string> payment id by idempotency key */
    public array $byKey = [];
    /** @var list<array{reference: string, amount: int, phone: string, key: string}> every createPayment call, replays included */
    public array $creates = [];
    public int $reads = 0;
    public ?GatewayException $failNextCreate = null;
    public ?GatewayException $failNextRead = null;
    private int $seq = 0;

    public function __construct(private readonly bool $configured = true)
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function createPayment(string $reference, int $amount, string $phone, string $description, string $idempotencyKey): GatewayPayment
    {
        $this->creates[] = ['reference' => $reference, 'amount' => $amount, 'phone' => $phone, 'key' => $idempotencyKey];
        if ($this->failNextCreate !== null) {
            $e = $this->failNextCreate;
            $this->failNextCreate = null;

            throw $e;
        }
        if (isset($this->byKey[$idempotencyKey])) {
            return $this->payments[$this->byKey[$idempotencyKey]];
        }
        $id = sprintf('pay_%024d', ++$this->seq);
        $this->byKey[$idempotencyKey] = $id;

        return $this->payments[$id] = new GatewayPayment($id, $reference, $amount, GatewayStatus::Pending);
    }

    public function getPayment(string $id): GatewayPayment
    {
        $this->reads++;
        if ($this->failNextRead !== null) {
            $e = $this->failNextRead;
            $this->failNextRead = null;

            throw $e;
        }

        return $this->payments[$id] ?? throw new GatewayException(GatewayException::NOT_FOUND, 'No such payment.');
    }

    /** @param list<string> $flags */
    public function report(string $id, GatewayStatus $status, ?string $receipt = null, array $flags = [], ?int $amount = null, ?string $reference = null): GatewayPayment
    {
        $was = $this->payments[$id];

        return $this->payments[$id] = new GatewayPayment($id, $reference ?? $was->reference, $amount ?? $was->amount, $status, $receipt, $flags, null, '');
    }

    public function lastId(): string
    {
        return array_key_last($this->payments) ?? throw new \LogicException('No payment was created.');
    }
}
