<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$query = [];
parse_str((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? ''), $query);

if ($path === '/render') {
    if (($query['status'] ?? '') === '503') {
        http_response_code(503);
        header('Content-Type: text/plain');
        echo 'down';
        exit;
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'v' => 1,
        'html' => '<div id="mapsight-embed-1" class="mapsight-embed"></div>',
        'state' => ['requestId' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null],
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($path === '/v1/og') {
    if (($query['status'] ?? '') === '302') {
        http_response_code(302);
        header('Location: /v1/og');
        header('Content-Type: text/plain');
        echo 'redirect';
        exit;
    }
    if (($query['status'] ?? '') === '503') {
        http_response_code(503);
        header('Content-Type: text/plain');
        echo 'down';
        exit;
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    $requestUrl = is_array($body) ? ($body['options']['requestUrl'] ?? '') : '';
    if (!is_string($requestUrl) || !str_contains($requestUrl, 'feature=')) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo '{"v":1,"error":{"code":"NOT_FOUND","message":"no og image for this request"}}';
        exit;
    }

    http_response_code(200);
    header('Content-Type: ' . (($query['image'] ?? '') === 'html' ? 'text/html' : 'image/png'));
    if (($query['image'] ?? '') === 'empty') {
        exit;
    }
    if (($query['image'] ?? '') === 'large') {
        echo str_repeat('x', 8388608);
        exit;
    }
    if (($query['image'] ?? '') === 'truncated') {
        header('Content-Length: 100');
        echo "\x89PNG\r\n\x1a\n";
        exit;
    }
    echo "\x89PNG\r\n\x1a\n" . ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    exit;
}

if ($path === '/purge') {
    if (($query['status'] ?? '') === '204') {
        http_response_code(204);
        exit;
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo '["doc::1"]';
    exit;
}

http_response_code(404);
header('Content-Type: text/plain');
echo 'not found';
