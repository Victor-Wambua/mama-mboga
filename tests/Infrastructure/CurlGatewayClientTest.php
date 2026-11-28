<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Infrastructure;

use MamaMboga\Application\Gateway\GatewayException;
use MamaMboga\Application\Gateway\GatewayStatus;
use MamaMboga\Infrastructure\Config\Config;
use MamaMboga\Infrastructure\Gateway\CurlGatewayClient;
use MamaMboga\Infrastructure\Gateway\UnconfiguredGatewayClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The curl gateway client: its requests and its reading of the gateway's answers. Nothing here reaches a real gateway. */
final class CurlGatewayClientTest extends TestCase
{
    /** @var list<array{string, string, array<mixed>, ?string}> */
    private array $sent = [];

    /**
     * @param array<string, string> $headers
     * @return callable(string, string, list<string>, ?string): array{0: int, 1: array<string, string>, 2: string}
     */
    private function answer(int $status, string $body, array $headers = []): callable
    {
        $transport = function (string $method, string $url, array $sentHeaders, ?string $payload) use ($status, $body, $headers): array {
            $this->sent[] = [$method, $url, $sentHeaders, $payload];

            return [$status, $headers, $body];
        };

        return $transport;
    }

    private function client(callable $transport): CurlGatewayClient
    {
        return new CurlGatewayClient('https://gateway.test/', 'sk_test_key', 10, $transport);
    }

    private const PENDING = '{"data":{"id":"pay_1","reference":"DENI-AAAAAA","amount":250,"status":"pending","flags":[]}}';

    public function testCreatingAPaymentSendsTheSecretKeyIdempotencyKeyAndBodyAndNothingElse(): void
    {
        $payment = $this->client($this->answer(202, self::PENDING))->createPayment('DENI-AAAAAA', 250, '254700000010', 'Deni payment', 'mm-0123456789ab');

        [$method, $url, $headers, $payload] = $this->sent[0];
        self::assertSame('POST', $method);
        self::assertSame('https://gateway.test/v1/payments', $url, 'no double slash');
        self::assertContains('Authorization: Bearer sk_test_key', $headers);
        self::assertContains('Idempotency-Key: mm-0123456789ab', $headers);
        self::assertContains('Content-Type: application/json', $headers);
        self::assertSame(['amount' => 250, 'phone' => '254700000010', 'reference' => 'DENI-AAAAAA', 'description' => 'Deni payment'], json_decode((string) $payload, true));
        self::assertSame('pay_1', $payment->id);
        self::assertSame(GatewayStatus::Pending, $payment->status);
    }

    public function testReadingAPaymentUsesTheIdAndParsesReceiptAndFlags(): void
    {
        $body = '{"data":{"id":"pay_1","reference":"DENI-AAAAAA","amount":250,"status":"needs_review","flags":["late_success"],"mpesa_receipt":"UKSI33RRGY","result_code":0,"result_desc":"ok"}}';
        $payment = $this->client($this->answer(200, $body))->getPayment('pay_1');

        self::assertSame(['GET', 'https://gateway.test/v1/payments/pay_1'], [$this->sent[0][0], $this->sent[0][1]]);
        self::assertNull($this->sent[0][3]);
        self::assertSame(GatewayStatus::NeedsReview, $payment->status);
        self::assertSame(['late_success'], $payment->flags);
        self::assertSame('UKSI33RRGY', $payment->receipt);
    }

    public function testAnUnsafeIdIsEncodedIntoThePath(): void
    {
        $this->client($this->answer(200, self::PENDING))->getPayment('../x?y');

        self::assertSame('https://gateway.test/v1/payments/..%2Fx%3Fy', $this->sent[0][1]);
    }

    /** @return array<string, array{int, string, array<string, string>, string, bool}> */
    public static function failures(): array
    {
        return [
            'rate limited honours Retry-After' => [429, '{"error":{"code":"rate_limited","message":"Too many."}}', ['retry-after' => '17'], GatewayException::RATE_LIMITED, true],
            'validation' => [422, '{"error":{"code":"validation_error","message":"phone must be a Kenyan mobile number"}}', [], GatewayException::REJECTED, false],
            'over the key cap' => [422, '{"error":{"code":"amount_exceeds_key_cap","message":"capped"}}', [], GatewayException::REJECTED, false],
            'idempotency conflict' => [409, '{"error":{"code":"idempotency_conflict","message":"different body"}}', [], GatewayException::REJECTED, false],
            'bad key' => [401, '{"error":{"code":"unauthorized","message":"no"}}', [], GatewayException::REJECTED, false],
            'reference still in flight' => [409, '{"error":{"code":"reference_pending","message":"in flight"}}', [], GatewayException::SERVER, true],
            'not found' => [404, '{"error":{"code":"not_found","message":"none"}}', [], GatewayException::NOT_FOUND, false],
            'server error' => [500, '{"error":{"code":"internal_error","message":"oops"}}', [], GatewayException::SERVER, true],
            'not even json' => [502, '<html>bad gateway</html>', [], GatewayException::SERVER, true],
        ];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('failures')]
    public function testGatewayErrorsAreMappedAndOnlySafeOnesAreRetryable(int $status, string $body, array $headers, string $kind, bool $retryable): void
    {
        try {
            $this->client($this->answer($status, $body, $headers))->getPayment('pay_1');
            self::fail('expected a gateway exception');
        } catch (GatewayException $e) {
            self::assertSame($kind, $e->kind);
            self::assertSame($retryable, $e->retryable());
            if ($status === 429) {
                self::assertSame(17, $e->retryAfterSeconds);
            }
        }
    }

    public function testASuccessWithoutAPaymentIsTreatedAsAServerFault(): void
    {
        $this->expectException(GatewayException::class);
        $this->client($this->answer(200, '{"data":{"hello":"world"}}'))->getPayment('pay_1');
    }

    public function testAnUnreachableGatewayIsRetryable(): void
    {
        $client = $this->client(static function (): array {
            throw new \RuntimeException('connection refused');
        });

        try {
            $client->getPayment('pay_1');
            self::fail('expected a gateway exception');
        } catch (GatewayException $e) {
            self::assertSame(GatewayException::UNREACHABLE, $e->kind);
            self::assertTrue($e->retryable());
        }
    }

    public function testNothingIsSentWithoutAUrlAndKey(): void
    {
        foreach ([new CurlGatewayClient('', 'k'), new CurlGatewayClient('https://g.test', ''), new UnconfiguredGatewayClient()] as $client) {
            self::assertFalse($client->isConfigured());
            try {
                $client->getPayment('pay_1');
                self::fail('expected a gateway exception');
            } catch (GatewayException $e) {
                self::assertSame(GatewayException::NOT_CONFIGURED, $e->kind);
            }
        }
        self::assertSame([], $this->sent);
    }

    public function testTheEnvironmentNamesSetTheGatewayAndKey(): void
    {
        $config = Config::fromEnv(['GATEWAY_URL' => 'https://gateway.example/', 'GATEWAY_SECRET_KEY' => 'sk_live_x'], '/tmp');
        self::assertSame('https://gateway.example', $config->gatewayUrl);
        self::assertSame('sk_live_x', $config->gatewaySecretKey);

        $none = Config::fromEnv([], '/tmp');
        self::assertSame(['', ''], [$none->gatewayUrl, $none->gatewaySecretKey]);
    }

    /** The real curl path, against a throwaway PHP server on localhost (never a real gateway). */
    public function testRealCurlTalksHttpToALocalStub(): void
    {
        if (!function_exists('curl_init') || !function_exists('proc_open')) {
            self::markTestSkipped('needs ext-curl and proc_open');
        }
        $port = 9130 + random_int(0, 9);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__) . '/Support/gateway_stub.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            self::markTestSkipped('could not start a local server');
        }

        try {
            $up = false;
            for ($i = 0; $i < 50 && !$up; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
                if ($socket !== false) {
                    fclose($socket);
                    $up = true;
                } else {
                    usleep(100_000);
                }
            }
            if (!$up) {
                self::markTestSkipped('local server did not start');
            }
            $client = new CurlGatewayClient("http://127.0.0.1:{$port}", 'sk_test_stub', 5);

            $created = $client->createPayment('DENI-STUB01', 250, '254700000010', 'Deni payment', 'mm-abcdef123456');
            self::assertSame(GatewayStatus::Pending, $created->status);
            $seen = json_decode((string) file_get_contents(sys_get_temp_dir() . '/mm-gateway-stub-last.json'), true);
            self::assertSame('mm-abcdef123456', $seen['key']);
            self::assertSame(250, $seen['body']['amount']);
            self::assertSame('application/json', $seen['type']);

            $read = $client->getPayment('pay_stub0001');
            self::assertSame(GatewayStatus::Succeeded, $read->status);
            self::assertSame('STUB123456', $read->receipt);

            try {
                $client->getPayment('pay_busy');
                self::fail('expected rate limiting');
            } catch (GatewayException $e) {
                self::assertSame(GatewayException::RATE_LIMITED, $e->kind);
                self::assertSame(17, $e->retryAfterSeconds);
            }
            try {
                (new CurlGatewayClient("http://127.0.0.1:{$port}", 'wrong', 5))->getPayment('pay_stub0001');
                self::fail('expected a refusal');
            } catch (GatewayException $e) {
                self::assertSame(GatewayException::REJECTED, $e->kind);
            }
            try {
                (new CurlGatewayClient('http://127.0.0.1:9', 'k', 2))->getPayment('pay_1');
                self::fail('expected unreachable');
            } catch (GatewayException $e) {
                self::assertSame(GatewayException::UNREACHABLE, $e->kind);
            }
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            @unlink(sys_get_temp_dir() . '/mm-gateway-stub-last.json');
        }
    }
}
