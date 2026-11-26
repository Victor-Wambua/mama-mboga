<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Support;

use MamaMboga\Infrastructure\Http\Kernel;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;

/** Drives the kernel in-process like a browser would: keeps cookies, scrapes CSRF tokens. */
final class HttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];
    public string $ip = '10.0.0.1';

    public function __construct(private readonly Kernel $kernel)
    {
    }

    /** @param array<string, string> $headers */
    public function get(string $uri, array $headers = []): Response
    {
        return $this->send('GET', $uri, [], $headers);
    }

    /**
     * POST with the CSRF token of the last page fetched, unless $csrf is false or a specific value.
     *
     * @param array<string, mixed> $data
     */
    public function post(string $uri, array $data = [], bool|string $csrf = true): Response
    {
        if ($csrf === true) {
            $data['_csrf'] = $this->token();
        } elseif (is_string($csrf)) {
            $data['_csrf'] = $csrf;
        }

        return $this->send('POST', $uri, $data);
    }

    public function login(string $pin): Response
    {
        $this->get('/owner/login');

        return $this->post('/owner/login', ['pin' => $pin]);
    }

    /** The session's CSRF token, as a page embeds it in its meta tag. */
    public function token(): string
    {
        preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $this->get('/offline')->body, $m);

        return $m[1] ?? '';
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function send(string $method, string $uri, array $data = [], array $headers = []): Response
    {
        $request = Request::create($method, $uri, $data, $headers, $this->cookies, ['REMOTE_ADDR' => $this->ip]);
        $response = $this->kernel->handle($request);

        foreach ($response->headerValues('Set-Cookie') as $line) {
            [$pair] = explode(';', $line, 2);
            [$name, $value] = explode('=', $pair, 2);
            if ($value === '') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $response;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }
}
