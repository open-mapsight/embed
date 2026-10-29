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
