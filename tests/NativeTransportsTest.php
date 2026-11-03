<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\NativeSsrOgImageTransport;
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

    #[DataProvider('paths')]
    public function test_og_reads_the_card_on_2xx(string $method): void
    {
        [$status, $contentType, $body] = $this->og($method, '/v1/og', '{"v":1,"options":{"requestUrl":"/plan/?feature=poi-1"}}', ['X-Request-Id' => 'req-9']);

        $this->assertSame(200, $status);
        $this->assertSame('image/png', $contentType);
        $this->assertStringStartsWith("\x89PNG", $body);
        $this->assertStringEndsWith('req-9', $body);
    }

    #[DataProvider('paths')]
    public function test_og_404_means_no_card_and_5xx_throws(string $method): void
    {
        [$status] = $this->og($method, '/v1/og', '{"v":1,"options":{"requestUrl":"/plan/"}}');
        $this->assertSame(404, $status);

        $transport = new NativeSsrOgImageTransport();
        $this->assertNull($transport->postOgImage(
            'http://127.0.0.1:' . self::$port . '/v1/og',
            ['v' => 1, 'options' => ['requestUrl' => '/plan/']],
            2.0,
        ));
        $this->assertSame(
            'image/png',
            $transport->postOgImage(
                'http://127.0.0.1:' . self::$port . '/v1/og',
                ['v' => 1, 'options' => ['requestUrl' => '/plan/?feature=poi-1']],
                2.0,
            )?->contentType,
        );

        $this->expectExceptionMessage('SSR og HTTP status 503');
        $transport->postOgImage('http://127.0.0.1:' . self::$port . '/v1/og?status=503', ['v' => 1], 2.0);
    }

    #[DataProvider('paths')]
    public function test_og_transport_does_not_follow_redirects(string $method): void
    {
        [$status, , $body] = $this->og($method, '/v1/og?status=302', '{}');

        $this->assertSame(302, $status);
        $this->assertSame('redirect', $body);
    }

    #[DataProvider('paths')]
    public function test_og_stops_reading_an_oversized_response_and_preserves_its_headers(string $method): void
    {
        [$status, $contentType, $body] = $this->og(
            $method,
            '/v1/og?image=large',
            '{"v":1,"options":{"requestUrl":"/plan/?feature=poi-1"}}',
        );

        $this->assertSame(200, $status);
        $this->assertSame('image/png', $contentType);
        $this->assertSame(NativeSsrOgImageTransport::MAX_IMAGE_BYTES + 1, strlen($body));
    }

    public function test_og_rejects_non_image_empty_and_oversized_responses(): void
    {
        $transport = new NativeSsrOgImageTransport();
        foreach (['html' => 'Content-Type', 'empty' => 'usable image', 'large' => 'usable image'] as $kind => $message) {
            try {
                $transport->postOgImage(
                    'http://127.0.0.1:' . self::$port . '/v1/og?image=' . $kind,
                    ['v' => 1, 'options' => ['requestUrl' => '/plan/?feature=poi-1']],
                    2.0,
                );
                $this->fail('Accepted ' . $kind . ' response');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    #[DataProvider('paths')]
    public function test_og_rejects_a_truncated_image_response(string $method): void
    {
        $this->expectException(\RuntimeException::class);
        $this->og($method, '/v1/og?image=truncated', '{"options":{"requestUrl":"/map/?feature=poi-1"}}');
    }

    public function test_og_rejects_an_oversized_request_before_contacting_the_sidecar(): void
    {
        $this->expectExceptionMessage('SSR request body exceeds size cap');
        (new NativeSsrOgImageTransport())->postOgImage(
            'http://127.0.0.1:1/v1/og',
            ['padding' => str_repeat('x', 262144)],
            0.1,
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: ?string, 2: string}
     */
    private function og(string $method, string $path, string $json, array $headers = []): array
    {
        $transport = new NativeSsrOgImageTransport();
        $url = 'http://127.0.0.1:' . self::$port . $path;
        $args = $method === 'postWithCurl'
            ? [$url, $json, 2.0, 0.5, $headers]
            : [$url, $json, 2.0, $headers];

        return (new \ReflectionMethod($transport, $method))->invoke($transport, ...$args);
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
