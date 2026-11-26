<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Security;

/** Per-visitor state, saved in SQLite and identified by a random cookie. */
final class Session
{
    private bool $dirty = false;
    private bool $destroyed = false;

    /** @param array<string, mixed> $data */
    public function __construct(private string $id, private array $data = [], private readonly bool $isNew = true)
    {
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
        $this->dirty = true;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function flash(string $type, string $message): void
    {
        /** @var list<array{type: string, message: string}> $all */
        $all = $this->data['_flash'] ?? [];
        $all[] = ['type' => $type, 'message' => $message];
        $this->put('_flash', $all);
    }

    /** @return list<array{type: string, message: string}> */
    public function pullFlash(): array
    {
        /** @var list<array{type: string, message: string}> $all */
        $all = $this->data['_flash'] ?? [];
        if ($all !== []) {
            $this->forget('_flash');
        }

        return $all;
    }

    /** A new id with the same data: call on login so an old id cannot be reused (session fixation). */
    public function regenerate(): string
    {
        $this->id = self::newId();
        $this->dirty = true;

        return $this->id;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed = true;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }
}
