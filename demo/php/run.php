<?php

declare(strict_types=1);

// Browser demo entry point: one request = one JSON action {"action": "...", "args": {...}}.
require __DIR__ . '/autoload.php';

use MamaMboga\Demo\Bridge;

date_default_timezone_set('Africa/Nairobi');
$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$action = is_string($input['action'] ?? null) ? $input['action'] : 'snapshot';
$args = is_array($input['args'] ?? null) ? $input['args'] : [];

header('Content-Type: application/json');
try {
    $bridge = Bridge::boot(dirname(__DIR__, 2), '/data/mama-mboga.sqlite');
    echo json_encode($bridge->handle($action, $args), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'errors' => [], 'message' => 'Unexpected error: ' . $e::class . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
