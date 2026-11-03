<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\EmbedRequest;
use OpenMapsight\Embed\SsrCacheKey;
use OpenMapsight\Embed\SsrCircuitBreaker;
use OpenMapsight\Embed\SsrOgImage;
use OpenMapsight\Embed\SsrOgImageClient;
use OpenMapsight\Embed\SsrOgImageTransport;
use OpenMapsight\Embed\SsrRequestPayload;
use PHPUnit\Framework\TestCase;

final class SsrOgImageClientTest extends TestCase
{
    public function test_posts_the_render_payload_to_v1_og(): void
    {
        $transport = $this->transport(new SsrOgImage('image/png', "\x89PNG"));
        $client = new SsrOgImageClient($transport);

        $image = $client->fetch($this->request(['requestId' => 'og-1', 'assetVersion' => 'bundle-1']));

        $this->assertSame("\x89PNG", $image?->body);
        $this->assertSame('image/png', $image?->contentType);
        $this->assertSame('http://ssr:4123/v1/og', $transport->url);
        $this->assertSame(1, $transport->payload['v']);
        $this->assertSame('placeMap', $transport->payload['preset']);
        $this->assertSame('og-1', $transport->payload['requestId']);
        $this->assertSame('/plan/?module=landmarks&feature=poi-1', $transport->payload['options']['requestUrl']);
        $this->assertSame('https://www.example.de/mapsight/og.php', $transport->payload['options']['ogImageEndpoint']);
        $this->assertSame('https://www.example.de', $transport->payload['options']['pageOrigin']);
        $this->assertSame('host-map', $transport->payload['options']['containerId']);
        $this->assertSame(1, $transport->payload['options']['project']['version']);
        $this->assertSame('og-1', $transport->headers['X-Request-Id']);
        $this->assertSame('bundle-1', $transport->headers['X-Mapsight-Asset-Version']);
        $this->assertSame('bundle-1', $transport->payload['assetVersion']);
        $this->assertSame('image/png, application/json', $transport->headers['Accept']);
        $this->assertSame(3.0, $transport->timeoutSeconds);
        $this->assertSame(0.2, $transport->connectTimeoutSeconds);
    }

    public function test_is_null_without_sidecar_or_share_param(): void
    {
        $transport = $this->transport(new SsrOgImage('image/png', "\x89PNG"));
        $client = new SsrOgImageClient($transport);

        $this->assertNull($client->fetch($this->request(['ssrUrl' => null])));
        $this->assertNull($client->fetch($this->request(['requestUrl' => '/plan/'])));
        $this->assertSame(0, $transport->calls);
    }

    public function test_is_null_when_the_sidecar_has_no_card(): void
    {
        $breaker = $this->breaker();
        $client = new SsrOgImageClient($this->transport(null), $breaker);

        $this->assertNull($client->fetch($this->request()));
        $this->assertSame(1, $breaker->successes);
        $this->assertSame(0, $breaker->failures);
    }

    public function test_fails_open_and_counts_a_failure(): void
    {
        $breaker = $this->breaker();
        $client = new SsrOgImageClient($this->transport(new \RuntimeException('SSR og HTTP status 503')), $breaker);

        $this->assertNull($client->fetch($this->request()));
        $this->assertSame(1, $breaker->failures);
    }

    public function test_skips_the_sidecar_while_the_breaker_is_open(): void
    {
        $breaker = $this->breaker(false);
        $transport = $this->transport(new SsrOgImage('image/png', "\x89PNG"));
        $client = new SsrOgImageClient($transport, $breaker);

        $this->assertNull($client->fetch($this->request()));
        $this->assertSame(0, $transport->calls);
    }

    public function test_endpoint_resolves_from_field_config_or_env_and_keys_the_cache(): void
    {
        $explicit = $this->request();
        $fromConfig = $this->request(['ogImageEndpoint' => null, 'config' => ['ogImageEndpoint' => 'https://cfg.example.de/og']]);
        $none = $this->request(['ogImageEndpoint' => null]);

        $this->assertSame('https://www.example.de/mapsight/og.php', $explicit->resolvedOgImageEndpoint());
        $this->assertSame('https://cfg.example.de/og', $fromConfig->resolvedOgImageEndpoint());
        $this->assertNull($none->resolvedOgImageEndpoint());
        $this->assertArrayNotHasKey('ogImageEndpoint', SsrRequestPayload::options($none));
        $this->assertNotSame(SsrCacheKey::for($explicit), SsrCacheKey::for($none));

        putenv('MAPSIGHT_OG_IMAGE_ENDPOINT=https://env.example.de/og');
        try {
            $this->assertSame('https://env.example.de/og', $none->resolvedOgImageEndpoint());
        } finally {
            putenv('MAPSIGHT_OG_IMAGE_ENDPOINT');
        }
    }

    public function test_source_and_module_hints_reach_the_sidecar_and_separate_cache_entries(): void
    {
        $transport = $this->transport(new SsrOgImage('image/png', "\x89PNG"));
        $client = new SsrOgImageClient($transport);
        $url = '/plan/?module=landmarks&feature=shared-id&src=places';
        $places = $this->request(['requestUrl' => $url]);
        $otherSource = $this->request(['requestUrl' => str_replace('src=places', 'src=museums', $url)]);
        $otherModule = $this->request(['requestUrl' => str_replace('module=landmarks', 'module=culture', $url)]);

        $this->assertNotNull($client->fetch($places));
        $this->assertSame($url, $transport->payload['options']['requestUrl']);
        $this->assertNotSame(SsrCacheKey::for($places), SsrCacheKey::for($otherSource));
        $this->assertNotSame(SsrCacheKey::for($places), SsrCacheKey::for($otherModule));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function request(array $overrides = []): EmbedRequest
    {
        $args = [
            'preset' => 'placeMap',
            'containerId' => 'host-map',
            'config' => ['project' => ['version' => 1]],
            'ssrUrl' => 'http://ssr:4123',
            'ssrTimeoutSeconds' => 3.0,
            'ssrConnectTimeoutSeconds' => 0.2,
            'requestUrl' => '/plan/?module=landmarks&feature=poi-1',
            'pageOrigin' => 'https://www.example.de',
            'ogImage' => '/plan/img/og-default.png',
            'ogImageEndpoint' => 'https://www.example.de/mapsight/og.php',
        ];

        return new EmbedRequest(...array_merge($args, $overrides));
    }

    private function transport(SsrOgImage|\Throwable|null $result): SsrOgImageTransport&RecordingOgTransport
    {
        return new class($result) implements SsrOgImageTransport, RecordingOgTransport {
            public int $calls = 0;
            public string $url = '';
            /** @var array<string, mixed> */
            public array $payload = [];
            /** @var array<string, string> */
            public array $headers = [];
            public float $timeoutSeconds = 0.0;
            public float $connectTimeoutSeconds = 0.0;

            public function __construct(private readonly SsrOgImage|\Throwable|null $result)
            {
            }

            public function postOgImage(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): ?SsrOgImage {
                $this->calls++;
                $this->url = $url;
                $this->payload = $payload;
                $this->headers = $headers;
                $this->timeoutSeconds = $timeoutSeconds;
                $this->connectTimeoutSeconds = $connectTimeoutSeconds;
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    private function breaker(bool $allow = true): SsrCircuitBreaker&CountingBreaker
    {
        return new class($allow) implements SsrCircuitBreaker, CountingBreaker {
            public int $successes = 0;
            public int $failures = 0;

            public function __construct(private readonly bool $allowed)
            {
            }

            public function allow(): bool
            {
                return $this->allowed;
            }

            public function recordSuccess(): void
            {
                $this->successes++;
            }

            public function recordFailure(): void
            {
                $this->failures++;
            }
        };
    }
}

/** @internal typed access to the anonymous transport's recordings */
interface RecordingOgTransport
{
}

/** @internal typed access to the anonymous breaker's counters */
interface CountingBreaker
{
}
