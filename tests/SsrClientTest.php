<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\ArraySsrResultCache;
use OpenMapsight\Embed\EmbedRequest;
use OpenMapsight\Embed\SsrCacheKey;
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

    public function test_og_image_posts_the_render_payload_and_returns_the_card(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrHttpResponse(200, 'image/png', "\x89PNG"));
        $client = new SsrClient(ssrUrl: 'http://ssr:4123', transport: $transport);

        $image = $client->ogImage($this->placeRequest());

        $this->assertSame('image/png', $image?->contentType);
        $this->assertSame("\x89PNG", $image?->body);
        $sent = $transport->requests[0];
        $this->assertSame('POST', $sent->method);
        $this->assertSame('http://ssr:4123/v1/og', $sent->url);
        $this->assertSame('image/png, application/json', $sent->headers['Accept']);
        $this->assertSame('og-1', $sent->headers['X-Request-Id']);
        $this->assertSame('bundle-1', $sent->headers['X-Mapsight-Asset-Version']);
        $payload = json_decode((string) $sent->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $payload['v']);
        $this->assertSame('og-1', $payload['requestId']);
        $this->assertSame('bundle-1', $payload['assetVersion']);
        $this->assertSame('placeMap', $payload['preset']);
        $this->assertSame('/plan/?feature=poi-1&module=landmarks', $payload['options']['requestUrl']);
        $this->assertSame('https://www.example.de/mapsight/og.php', $payload['options']['ogImageEndpoint']);
        $this->assertSame('/plan/img/og-default.png', $payload['options']['ogImage']);
        $this->assertSame('https://www.example.de', $payload['options']['pageOrigin']);
    }

    public function test_og_image_is_null_without_a_share_param(): void
    {
        $transport = new FakeSsrTransport();
        $client = new SsrClient(ssrUrl: 'http://ssr:4123', transport: $transport);

        $this->assertNull($client->ogImage($this->placeRequest('/plan/')));
        $this->assertSame([], $transport->requests);
    }

    public function test_og_image_is_null_when_the_sidecar_has_no_card_or_answers_badly(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrHttpResponse(404, 'application/json', '{"v":1,"error":{"code":"NOT_FOUND","message":"no og image"}}'));
        $transport->queue(new SsrHttpResponse(200, 'text/html', '<html>'));
        $transport->queue(new SsrHttpResponse(503, 'text/plain', 'down'));
        $transport->queue(new \OpenMapsight\Embed\SsrUnavailable('connect timeout'));
        $client = new SsrClient(ssrUrl: 'http://ssr:4123', transport: $transport);

        $this->assertNull($client->ogImage($this->placeRequest()));
        $this->assertNull($client->ogImage($this->placeRequest()));
        $this->assertNull($client->ogImage($this->placeRequest()));
        $this->assertNull($client->ogImage($this->placeRequest()));
        $this->assertCount(4, $transport->requests);
    }

    public function test_cache_key_includes_the_og_image_endpoint(): void
    {
        $url = '/plan/?module=landmarks&feature=poi-1';
        $with = $this->placeRequest($url);
        $without = $this->placeRequest($url, null);

        $this->assertNotSame(SsrCacheKey::for($with, $with->requestUrl), SsrCacheKey::for($without, $without->requestUrl));
    }

    public function test_og_image_rejects_empty_and_oversized_images(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrHttpResponse(200, 'image/png', ''));
        $transport->queue(new SsrHttpResponse(200, 'image/png', str_repeat('x', SsrClient::MAX_IMAGE_BYTES + 1)));
        $client = new SsrClient(ssrUrl: 'http://ssr:4123', transport: $transport);

        $this->assertNull($client->ogImage($this->placeRequest()));
        $this->assertNull($client->ogImage($this->placeRequest()));
        $this->assertCount(2, $transport->requests);
    }

    public function test_og_request_size_cap_is_checked_before_transport(): void
    {
        $transport = new FakeSsrTransport();
        $client = new SsrClient(ssrUrl: 'http://ssr:4123', transport: $transport);
        $request = new EmbedRequest(
            preset: 'placeMap',
            containerId: 'host-map',
            config: ['padding' => str_repeat('x', 262144)],
            requestUrl: '/plan/?feature=poi-1',
        );

        $this->assertNull($client->ogImage($request));
        $this->assertSame([], $transport->requests);
    }

    public function test_source_and_module_hints_are_forwarded_and_do_not_reuse_another_placement(): void
    {
        $transport = new FakeSsrTransport();
        for ($i = 0; $i < 3; $i++) {
            $transport->queueV1('<div id="host-map"></div>');
        }
        $client = new SsrClient(
            ssrUrl: 'http://ssr:4123',
            transport: $transport,
            resultCache: new ArraySsrResultCache(),
            shareParams: ['module', 'feature', 'src'],
        );
        $url = '/plan/?module=landmarks&feature=shared-id&src=places';
        $places = $this->placeRequest($url);
        $otherSource = $this->placeRequest(str_replace('src=places', 'src=museums', $url));
        $otherModule = $this->placeRequest(str_replace('module=landmarks', 'module=culture', $url));

        $this->assertSame(SsrOutcome::Rendered, $client->resolve($places)->outcome);
        $this->assertSame(SsrOutcome::Cached, $client->resolve($places)->outcome);
        $this->assertSame(SsrOutcome::Rendered, $client->resolve($otherSource)->outcome);
        $this->assertSame(SsrOutcome::Rendered, $client->resolve($otherModule)->outcome);
        $this->assertCount(3, $transport->requests);
        $payload = json_decode((string) $transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/plan/?feature=shared-id&module=landmarks&src=places', $payload['options']['requestUrl']);
    }

    private function placeRequest(
        string $requestUrl = '/plan/?module=landmarks&feature=poi-1&x=1',
        ?string $ogImageEndpoint = 'https://www.example.de/mapsight/og.php',
    ): EmbedRequest
    {
        return new EmbedRequest(
            preset: 'placeMap',
            containerId: 'host-map',
            config: ['project' => ['version' => 1]],
            requestId: 'og-1',
            assetVersion: 'bundle-1',
            requestUrl: $requestUrl,
            pageOrigin: 'https://www.example.de',
            ogImage: '/plan/img/og-default.png',
            ogImageEndpoint: $ogImageEndpoint,
        );
    }
}
