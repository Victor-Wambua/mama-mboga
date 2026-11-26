<?php

declare(strict_types=1);

// PHP's built-in server: let it serve real files (CSS, JS, icons) itself.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (is_file($file)) {
        return false;
    }
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use MamaMboga\App;
use MamaMboga\Infrastructure\Config\Config;
use MamaMboga\Infrastructure\Http\Request;

$env = Config::readEnvFile($root . '/.env') + array_map('strval', array_filter(getenv(), 'is_string'));
$config = Config::fromEnv($env, $root);
date_default_timezone_set($config->timezone);

if (!is_file($config->dbPath) && $config->dbPath !== ':memory:') {
    http_response_code(503);
    echo "The database is not set up yet. Run: composer setup && composer seed\n";
    exit;
}

App::kernel(App::container($config))->handle(Request::fromGlobals())->send();
