<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\NativeSsrPurgeTransport;
use OpenMapsight\Embed\NativeSsrTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeTransportsTest extends TestCase
{
    private static int $port = 0;

    /** @var resource|false */
    private static $process = false;

    public static function setUpBeforeClass(): void
    {
        self::$port = 18700 + random_int(0, 200);
        self::$process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fixtures/http-server.php'],
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

        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if (is_resource($fp)) {
                fclose($fp);

                return;
            }
            usleep(50000);
        }

        self::tearDownAfterClass();
        self::markTestSkipped('php -S did not become ready');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== false) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = false;
        }
    }

    /** @return iterable<string, array{string}> */
    public static function paths(): iterable
    {
        yield 'curl' => ['postWithCurl'];
        yield 'streams' => ['postWithStreams'];
    }

    #[DataProvider('paths')]
    public function test_render_reads_body_on_2xx(string $method): void
    {
        $body = $this->render($method, '/render', ['X-Request-Id' => 'req-1']);

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['v'] ?? null);
        $this->assertSame('req-1', $data['state']['requestId'] ?? null);
    }

    #[DataProvider('paths')]
    public function test_render_throws_on_5xx(string $method): void
    {
        $this->expectExceptionMessage('SSR HTTP status 503');
        $this->render($method, '/render?status=503');
    }

    #[DataProvider('paths')]
    public function test_purge_reads_status(string $method): void
    {
        $this->assertSame('["doc::1"]', $this->purge($method, '/purge'));
        $this->assertSame('[]', $this->purge($method, '/purge?status=204'));
    }

    /** @param array<string, string> $headers */
    private function render(string $method, string $path, array $headers = []): string
    {
        $transport = new NativeSsrTransport();
        $url = 'http://127.0.0.1:' . self::$port . $path;
        $args = $method === 'postWithCurl'
            ? [$url, '{"v":1}', 2.0, 0.5, $headers]
            : [$url, '{"v":1}', 2.0, $headers];

        return (new \ReflectionMethod($transport, $method))->invoke($transport, ...$args);
    }

    private function purge(string $method, string $path): string
    {
        $transport = new NativeSsrPurgeTransport();
        $url = 'http://127.0.0.1:' . self::$port . $path;
        $args = $method === 'postWithCurl' ? [$url, '{}', 2.0, 0.5] : [$url, '{}', 2.0];

        return (new \ReflectionMethod($transport, $method))->invoke($transport, ...$args);
    }
}
