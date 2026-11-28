<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Gateway;

use MamaMboga\Application\Gateway\GatewayException;
use MamaMboga\Application\Gateway\GatewayPayment;
use MamaMboga\Application\Port\GatewayClient;

/**
 * Talks to the payments gateway over HTTP with curl (`docs/API.md` in github.com/Victor-Wambua/mpesa-gateway).
 * The secret key goes in the Authorization header from the server only; it is never sent to a browser.
 *
 * `$transport` exists so tests can stand in for the network: it receives (method, url, headers, body or null)
 * and returns [http status, response headers (lower-case names), body text], or throws \RuntimeException
 * when the gateway cannot be reached. Left out, a curl request is made.
 */
final class CurlGatewayClient implements GatewayClient
{
    /** @var (callable(string, string, list<string>, ?string): array{0: int, 1: array<string, string>, 2: string})|null */
    private $transport;

    /** @param (callable(string, string, list<string>, ?string): array{0: int, 1: array<string, string>, 2: string})|null $transport */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secretKey,
        private readonly int $timeoutSeconds = 10,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->secretKey !== '';
    }

    public function createPayment(string $reference, int $amount, string $phone, string $description, string $idempotencyKey): GatewayPayment
    {
        $body = json_encode(['amount' => $amount, 'phone' => $phone, 'reference' => $reference, 'description' => $description], JSON_THROW_ON_ERROR);

        return $this->payment($this->call('POST', '/v1/payments', $body, $idempotencyKey));
    }

    public function getPayment(string $id): GatewayPayment
    {
        return $this->payment($this->call('GET', '/v1/payments/' . rawurlencode($id), null, null));
    }

    /** @param array<mixed> $data */
    private function payment(array $data): GatewayPayment
    {
        try {
            return GatewayPayment::fromArray($data);
        } catch (\InvalidArgumentException) {
            throw new GatewayException(GatewayException::SERVER, 'The gateway answered with something that is not a payment.');
        }
    }

    /** @return array<mixed> the `data` object */
    private function call(string $method, string $path, ?string $body, ?string $idempotencyKey): array
    {
        if (!$this->isConfigured()) {
            throw new GatewayException(GatewayException::NOT_CONFIGURED, 'The payments gateway is not set up.');
        }
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->secretKey];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        try {
            [$status, $responseHeaders, $text] = ($this->transport ?? $this->curl(...))($method, rtrim($this->baseUrl, '/') . $path, $headers, $body);
        } catch (\RuntimeException) {
            throw new GatewayException(GatewayException::UNREACHABLE, 'Could not reach the gateway.');
        }

        $json = json_decode($text, true);
        $json = is_array($json) ? $json : [];
        if ($status >= 200 && $status < 300 && is_array($json['data'] ?? null)) {
            return $json['data'];
        }

        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $message = is_string($error['message'] ?? null) && $error['message'] !== '' ? $error['message'] : "The gateway answered with HTTP {$status}.";
        $code = is_string($error['code'] ?? null) ? $error['code'] : '';

        if ($status === 429) {
            $retry = $responseHeaders['retry-after'] ?? '';

            throw new GatewayException(GatewayException::RATE_LIMITED, $message, ctype_digit($retry) ? min(600, max(1, (int) $retry)) : 30);
        }
        if ($status === 404) {
            throw new GatewayException(GatewayException::NOT_FOUND, $message);
        }
        if ($code === 'reference_pending') {
            throw new GatewayException(GatewayException::SERVER, $message); // in flight already: ask again later, do not mark it failed
        }
        if ($status === 401 || $status === 400 || $status === 409 || $status === 422) {
            throw new GatewayException(GatewayException::REJECTED, $message);
        }

        throw new GatewayException(GatewayException::SERVER, $message);
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new GatewayException(GatewayException::NOT_CONFIGURED, 'The PHP curl extension is not installed.');
        }
        $responseHeaders = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl could not start.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => $method === 'POST',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($text) || $status === 0) {
            throw new \RuntimeException('No answer from the gateway.');
        }

        return [$status, $responseHeaders, $text];
    }
}
