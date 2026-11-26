<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Http;

final class Response
{
    /** @param array<string, list<string>> $headers keyed by canonical-case name */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => ['text/html; charset=utf-8']]);
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain'): self
    {
        return new self($status, $body, ['Content-Type' => [$type . '; charset=utf-8']]);
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ['Content-Type' => ['application/json']]);
    }

    /** 303 so a POST never replays on refresh. */
    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, '', ['Location' => [$location]]);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [...$this->headers, $name => [$value]]);
    }

    public function withAddedHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [...$this->headers, $name => [...($this->headers[$name] ?? []), $value]]);
    }

    public function withStatus(int $status): self
    {
        return new self($status, $this->body, $this->headers);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values;
            }
        }

        return [];
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $values) {
            foreach ($values as $i => $value) {
                header($name . ': ' . $value, $i === 0 && strcasecmp($name, 'Set-Cookie') !== 0);
            }
        }
        echo $this->body;
    }
}
