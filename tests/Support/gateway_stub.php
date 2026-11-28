<?php

declare(strict_types=1);

// A tiny fake payments gateway for the curl test, run with `php -S`. It answers like docs/API.md and records what it was sent.
// It never leaves localhost.

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
header('Content-Type: application/json');

if ($path === '/seen') {
    echo (string) @file_get_contents(sys_get_temp_dir() . '/mm-gateway-stub-' . ($_GET['id'] ?? 'x') . '.json');

    return;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer sk_test_stub') {
    http_response_code(401);
    echo json_encode(['error' => ['code' => 'unauthorized', 'message' => 'Missing or unknown key.']]);

    return;
}
$payment = static fn (string $status): array => [
    'id' => 'pay_stub0001', 'reference' => 'DENI-STUB01', 'amount' => 250, 'currency' => 'KES', 'phone_masked' => '2547****010',
    'status' => $status, 'flags' => [], 'checkout_request_id' => 'ws_CO_STUB',
] + ($status === 'succeeded' ? ['mpesa_receipt' => 'STUB123456', 'result_code' => 0, 'result_desc' => 'Processed.'] : []);

if ($method === 'POST' && $path === '/v1/payments') {
    $body = (string) file_get_contents('php://input');
    file_put_contents(sys_get_temp_dir() . '/mm-gateway-stub-last.json', json_encode(['body' => json_decode($body, true), 'key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null, 'type' => $_SERVER['CONTENT_TYPE'] ?? null]));
    http_response_code(202);
    echo json_encode(['data' => $payment('pending')]);

    return;
}
if ($method === 'GET' && $path === '/v1/payments/pay_stub0001') {
    echo json_encode(['data' => $payment('succeeded')]);

    return;
}
if ($path === '/v1/payments/pay_busy') {
    http_response_code(429);
    header('Retry-After: 17');
    echo json_encode(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests.']]);

    return;
}
http_response_code(404);
echo json_encode(['error' => ['code' => 'not_found', 'message' => 'No such payment.']]);
