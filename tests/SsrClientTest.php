<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\ArraySsrResultCache;
use OpenMapsight\Embed\EmbedRequest;
use OpenMapsight\Embed\SsrClient;
use OpenMapsight\Embed\SsrHttpResponse;
use OpenMapsight\Embed\SsrOutcome;
use OpenMapsight\Embed\Testing\FakeSsrTransport;
use PHPUnit\Framework\TestCase;

final class SsrClientTest extends TestCase
{
    public function test_health_is_true_on_2xx(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrHttpResponse(200, 'text/plain', 'ok'));
        $client = new SsrClient(ssrUrl: 'http://ssr:4123', transport: $transport);

        $this->assertTrue($client->health());
        $this->assertSame('GET', $transport->requests[0]->method);
        $this->assertSame('http://ssr:4123/health', $transport->requests[0]->url);
    }

    public function test_health_is_false_on_transport_error(): void
    {
        $transport = new FakeSsrTransport();
        $client = new SsrClient(ssrUrl: 'http://ssr:4123', transport: $transport);

        $this->assertFalse($client->health());
    }

    public function test_warm_forces_a_sidecar_call_and_stores_the_result(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queueV1('<div id="mapsight-embed-warm"></div>', ['n' => 1]);
        $transport->queueV1('<div id="mapsight-embed-warm"></div>', ['n' => 2]);
        $cache = new ArraySsrResultCache();
        $client = new SsrClient(
            ssrUrl: 'http://ssr:4123',
            transport: $transport,
            resultCache: $cache,
        );
        $request = new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-warm',
            config: [],
        );

        $this->assertSame(SsrOutcome::Rendered, $client->resolve($request)->outcome);
        $this->assertSame(SsrOutcome::Rendered, $client->warm($request));
        $this->assertCount(2, $transport->requests);
        $this->assertSame(SsrOutcome::Cached, $client->resolve($request)->outcome);
        $this->assertCount(2, $transport->requests);
    }

    public function test_rejects_non_json_content_type(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrHttpResponse(
            200,
            'text/html',
            '<div id="mapsight-embed-1" data-dehydrated-state="{}"></div>',
        ));
        $result = (new SsrClient(ssrUrl: 'http://ssr:4123', transport: $transport))
            ->resolve(new EmbedRequest(
                preset: 'simpleMap',
                containerId: 'mapsight-embed-1',
                config: [],
            ));

        $this->assertSame(SsrOutcome::SkippedError, $result->outcome);
        $this->assertStringContainsString('Content-Type', (string) $result->reason);
    }

    public function test_four_xx_does_not_count_as_unavailable(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrHttpResponse(400, 'application/json', '{"v":1,"error":{"code":"VALIDATION"}}'));
        $transport->queueV1('<div id="mapsight-embed-1"></div>');
        $client = new SsrClient(
            ssrUrl: 'http://ssr:4123',
            transport: $transport,
            breaker: new \OpenMapsight\Embed\ProcessSsrCircuitBreaker(1, 60.0),
        );
        $request = new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-1',
            config: [],
        );

        $this->assertSame(SsrOutcome::SkippedError, $client->resolve($request)->outcome);
        $this->assertSame(SsrOutcome::Rendered, $client->resolve($request)->outcome);
    }

    public function test_normalizes_long_urls_to_path_only(): void
    {
        $client = new SsrClient(ssrUrl: 'http://ssr:4123', transport: new FakeSsrTransport());
        $url = '/map?' . str_repeat('x=1&', 600) . 'feature=1';

        $this->assertSame('/map', $client->normalizeRequestUrl($url));
    }

    public function test_array_cache_expires_and_evicts(): void
    {
        $cache = new ArraySsrResultCache();
        $cache->set('old', new \OpenMapsight\Embed\SsrDocument('<div id="a"></div>'), 1);
        $this->assertNotNull($cache->get('old'));
        sleep(2);
        $this->assertNull($cache->get('old'));

        for ($i = 0; $i < ArraySsrResultCache::MAX_ENTRIES + 2; $i++) {
            $cache->set('k' . $i, new \OpenMapsight\Embed\SsrDocument('<div id="c"></div>'), 60);
        }
        $this->assertNull($cache->get('k0'));
        $this->assertNotNull($cache->get('k' . (ArraySsrResultCache::MAX_ENTRIES + 1)));
    }
}
