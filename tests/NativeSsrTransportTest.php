<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\NativeSsrTransport;
use OpenMapsight\Embed\SsrHttpRequest;
use OpenMapsight\Embed\SsrUnavailable;
use PHPUnit\Framework\TestCase;

final class NativeSsrTransportTest extends TestCase
{
    private static int $port = 0;

    /** @var resource|false */
    private static $process = false;

    public static function setUpBeforeClass(): void
    {
        self::$port = 18700 + random_int(0, 200);
        $cmd = [
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . self::$port,
            __DIR__ . '/fixtures/http-server.php',
        ];
        self::$process = proc_open(
            $cmd,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
        );
        if (self::$process === false) {
            self::markTestSkipped('could not start php -S');
        }

        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if (is_resource($fp)) {
                fclose($fp);
                $ready = true;
                break;
            }
            usleep(50000);
        }
        if (!$ready) {
            self::stopServer();
            self::markTestSkipped('php -S did not become ready');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
    }

    public function test_posts_json_and_returns_status_and_content_type(): void
    {
        $response = (new NativeSsrTransport())->send(new SsrHttpRequest(
            'POST',
            $this->url('/v1/render'),
            ['Accept' => 'application/json', 'X-Request-Id' => 'req-1'],
            '{"v":1}',
            2.0,
            0.5,
        ));

        $this->assertSame(200, $response->status);
        $this->assertNotFalse(stripos((string) $response->contentType, 'application/json'));
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['v'] ?? null);
        $this->assertSame('req-1', $data['state']['headers']['x-request-id'] ?? null);
    }

    public function test_returns_4xx_and_5xx_without_throwing(): void
    {
        $transport = new NativeSsrTransport();
        $bad = $transport->send(new SsrHttpRequest('POST', $this->url('/v1/render?status=400'), [], '{}'));
        $down = $transport->send(new SsrHttpRequest('POST', $this->url('/v1/render?status=503'), [], '{}'));

        $this->assertSame(400, $bad->status);
        $this->assertSame(503, $down->status);
    }

    public function test_health_get(): void
    {
        $response = (new NativeSsrTransport())->send(new SsrHttpRequest(
            'GET',
            $this->url('/health'),
        ));

        $this->assertSame(200, $response->status);
        $this->assertSame('ok', $response->body);
    }

    public function test_timeout_throws_unavailable(): void
    {
        $this->expectException(SsrUnavailable::class);
        (new NativeSsrTransport())->send(new SsrHttpRequest(
            'GET',
            $this->url('/sleep'),
            [],
            null,
            0.05,
            0.05,
        ));
    }

    public function test_raw_html_content_type_is_preserved(): void
    {
        $response = (new NativeSsrTransport())->send(new SsrHttpRequest(
            'POST',
            $this->url('/v1/render?raw=1'),
            [],
            '{}',
        ));

        $this->assertSame(200, $response->status);
        $this->assertNotFalse(stripos((string) $response->contentType, 'text/html'));
        $this->assertStringContainsString('data-dehydrated-state', $response->body);
    }

    public function test_streams_fallback_reads_status_and_content_type(): void
    {
        $transport = new NativeSsrTransport();
        $withStreams = new \ReflectionMethod($transport, 'withStreams');
        $response = $withStreams->invoke($transport, new SsrHttpRequest(
            'POST',
            $this->url('/v1/render'),
            ['Accept' => 'application/json', 'X-Request-Id' => 'req-streams'],
            '{"v":1}',
            2.0,
            0.5,
        ));

        $this->assertSame(200, $response->status);
        $this->assertNotFalse(stripos((string) $response->contentType, 'application/json'));
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['v'] ?? null);
        $this->assertSame('req-streams', $data['state']['headers']['x-request-id'] ?? null);
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    private static function stopServer(): void
    {
        if (self::$process !== false) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = false;
        }
    }
}
