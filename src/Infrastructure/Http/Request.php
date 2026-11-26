<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Http;

/** An incoming HTTP request. Immutable apart from attributes added by middleware via withAttribute(). */
final class Request
{
    /**
     * @param array<string, string>      $query
     * @param array<string, mixed>       $body
     * @param array<string, string>      $headers lower-case names
     * @param array<string, string>      $cookies
     * @param array<string, mixed>       $server
     * @param array<string, mixed>       $attributes
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly array $server = [],
        private readonly array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($server[$key]) && is_string($server[$key])) {
                $headers[$name] = $server[$key];
            }
        }

        /** @var array<string, string> $query */
        $query = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $_GET);
        /** @var array<string, string> $cookies */
        $cookies = array_filter($_COOKIE, 'is_string');

        return new self(strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')), $path, $query, $_POST, $headers, $cookies, $server);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $server
     */
    public static function create(string $method, string $uri, array $body = [], array $headers = [], array $cookies = [], array $server = []): self
    {
        $parts = parse_url($uri) ?: [];
        parse_str($parts['query'] ?? '', $query);
        /** @var array<string, string> $query */
        $query = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $query);

        return new self(
            strtoupper($method),
            $parts['path'] ?? '/',
            $query,
            $body,
            array_change_key_case($headers, CASE_LOWER),
            $cookies,
            $server + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost:8080'],
        );
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(string $key, string $default = ''): string
    {
        return $this->query[$key] ?? $default;
    }

    /** A form field as a string ('' when absent or not a string). */
    public function input(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /** @return array<int|string, string> an array-valued form field such as qty[3]=2 */
    public function inputArray(string $key): array
    {
        $value = $this->body[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_filter($value, 'is_string');
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on' || $this->header('x-forwarded-proto') === 'https';
    }

    public function baseUrl(): string
    {
        $host = (string) ($this->server['HTTP_HOST'] ?? $this->header('host', 'localhost'));

        return ($this->isSecure() ? 'https' : 'http') . '://' . $host;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $this->headers, $this->cookies, $this->server, [...$this->attributes, $name => $value]);
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /** A route parameter, e.g. {id}. */
    public function param(string $name): string
    {
        $params = $this->attributes['route.params'] ?? [];

        return is_array($params) && is_string($params[$name] ?? null) ? $params[$name] : '';
    }
}
