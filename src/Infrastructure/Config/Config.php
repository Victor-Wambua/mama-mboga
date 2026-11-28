<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Config;

final readonly class Config
{
    public const PLACEHOLDER_KEY = 'change-me-run-composer-setup';

    public function __construct(
        public string $root,
        public string $env,
        public bool $debug,
        public string $appKey,
        public string $dbPath,
        public string $timezone,
        public string $appUrl,
        public bool $trustProxy = false,
        public string $gatewayUrl = '',
        public string $gatewaySecretKey = '',
    ) {
    }

    /** @param array<string, string> $env */
    public static function fromEnv(array $env, string $root): self
    {
        $appEnv = $env['APP_ENV'] ?? 'local';
        $key = $env['APP_KEY'] ?? self::PLACEHOLDER_KEY;
        if ($appEnv === 'production' && ($key === self::PLACEHOLDER_KEY || strlen($key) < 32)) {
            throw new \RuntimeException('APP_KEY must be set to a random string of at least 32 characters in production.');
        }
        $db = $env['DB_PATH'] ?? 'var/mama-mboga.sqlite';

        return new self(
            $root,
            $appEnv,
            filter_var($env['APP_DEBUG'] ?? '0', FILTER_VALIDATE_BOOLEAN),
            $key,
            $db === ':memory:' || str_starts_with($db, '/') ? $db : $root . '/' . $db,
            $env['APP_TIMEZONE'] ?? 'Africa/Nairobi',
            rtrim($env['APP_URL'] ?? '', '/'),
            filter_var($env['TRUST_PROXY'] ?? '0', FILTER_VALIDATE_BOOLEAN),
            rtrim($env['GATEWAY_URL'] ?? '', '/'),
            $env['GATEWAY_SECRET_KEY'] ?? '',
        );
    }

    /**
     * Load KEY=VALUE lines from a .env file.
     *
     * @return array<string, string>
     */
    public static function readEnvFile(string $file): array
    {
        $vars = [];
        if (!is_file($file)) {
            return $vars;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $vars[trim($k)] = trim(trim($v), '"\'');
        }

        return $vars;
    }
}
