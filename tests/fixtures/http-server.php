<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$query = [];
parse_str((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? ''), $query);

if ($path === '/health') {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'ok';
    exit;
}

if ($path === '/sleep') {
    usleep(400000);
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
}

if ($path === '/v1/render') {
    $status = (string) ($query['status'] ?? '200');
    if ($status === '400') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo '{"v":1,"error":{"code":"VALIDATION"}}';
        exit;
    }
    if ($status === '503') {
        http_response_code(503);
        header('Content-Type: text/plain');
        echo 'down';
        exit;
    }
    if (($query['raw'] ?? '') === '1') {
        http_response_code(200);
        header('Content-Type: text/html');
        echo '<div id="mapsight-embed-1" data-dehydrated-state="{}"></div>';
        exit;
    }
    if (($query['bom'] ?? '') === '1') {
        http_response_code(200);
        header('Content-Type: application/json');
        echo "\xEF\xBB\xBF" . '{"v":1,"html":"<div id=\"mapsight-embed-1\"></div>","state":{"bom":true}}';
        exit;
    }

    http_response_code(200);
    header('Content-Type: application/json');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    echo json_encode([
        'v' => 1,
        'html' => '<div id="mapsight-embed-1" class="mapsight-embed"></div>',
        'state' => [
            'echo' => $payload,
            'headers' => [
                'x-request-id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
                'x-mapsight-asset-version' => $_SERVER['HTTP_X_MAPSIGHT_ASSET_VERSION'] ?? null,
            ],
        ],
        'pageMeta' => null,
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
