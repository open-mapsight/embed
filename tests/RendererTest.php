<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\ArraySsrResultCache;
use OpenMapsight\Embed\EmbedRequest;
use OpenMapsight\Embed\ProcessSsrCircuitBreaker;
use OpenMapsight\Embed\Renderer;
use OpenMapsight\Embed\SsrCacheKey;
use OpenMapsight\Embed\SsrClient;
use OpenMapsight\Embed\SsrClientError;
use OpenMapsight\Embed\SsrOutcome;
use OpenMapsight\Embed\SsrUnavailable;
use OpenMapsight\Embed\Testing\FakeSsrTransport;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    public function test_rejects_connect_timeout_longer_than_total(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SsrClient(
            ssrUrl: 'http://ssr:4123',
            timeoutSeconds: 0.05,
            connectTimeoutSeconds: 0.1,
            transport: new FakeSsrTransport(),
        );
    }

    public function test_client_only_emits_css_preload_container_and_mount_boot(): void
    {
        $result = (new Renderer())->render(new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-demo',
            config: [
                'imagesUrl' => '/mapsight/plan/img/',
                'enableMap' => true,
                'view' => 'desktop',
            ],
        ));

        $html = $result->html;
        $this->assertSame(SsrOutcome::Disabled, $result->ssr);
        $this->assertStringContainsString('href="/mapsight/plan/assets/mapsight.css"', $html);
        $this->assertStringContainsString('rel="modulepreload" href="/mapsight/plan/assets/embed.js"', $html);
        $this->assertStringContainsString('rel="modulepreload" href="/mapsight/plan/assets/simpleMap.js"', $html);
        $this->assertStringContainsString('<div id="mapsight-embed-demo"></div>', $html);
        $this->assertStringContainsString('import {mountEmbed} from "/mapsight/plan/assets/embed.js"', $html);
        $this->assertStringContainsString('import {simpleMap} from "/mapsight/plan/assets/simpleMap.js"', $html);
        $this->assertStringContainsString('mountEmbed("mapsight-embed-demo"', $html);
        $this->assertStringContainsString('"enableMap":true', $html);
        $this->assertStringNotContainsString('data-dehydrated-state', $html);
        $this->assertStringNotContainsString('mapsight-ssr-skipped', $html);
        $this->assertStringNotContainsString('stadtplan', $html);
        $this->assertSame($result->stylesheetHtml, $result->html === '' ? '' : trim(explode("\n", $html)[0]));
    }

    public function test_host_supplies_container_class_on_empty_mount(): void
    {
        $html = (new Renderer())->render(new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-demo',
            config: [],
            containerClassName: 'host-embed',
        ))->html;

        $this->assertStringContainsString('<div id="mapsight-embed-demo" class="host-embed"></div>', $html);
    }

    public function test_ssr_success_injects_dehydrated_fragment_and_boot(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queueV1(
            '<div id="mapsight-embed-ssr" class="mapsight-embed"></div>',
            ['app' => ['title' => 'ok']],
        );
        $result = $this->renderer($transport)->render(new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-ssr',
            config: ['imagesUrl' => '/mapsight/plan/img/', 'enableMap' => true],
            requestId: 'req-1',
            assetVersion: 'assets-9',
        ));

        $html = $result->html;
        $this->assertSame(SsrOutcome::Rendered, $result->ssr);
        $this->assertStringContainsString('data-dehydrated-state=', $html);
        $this->assertStringContainsString('href="/mapsight/plan/assets/mapsight.css?v=assets-9"', $html);
        $this->assertStringContainsString('import {mountEmbed} from "/mapsight/plan/assets/embed.js?v=assets-9"', $html);
        $this->assertStringContainsString('import {simpleMap} from "/mapsight/plan/assets/simpleMap.js?v=assets-9"', $html);
        $this->assertStringContainsString('mountEmbed("mapsight-embed-ssr"', $html);
        $this->assertStringNotContainsString('mapsight-ssr-skipped', $html);
        $this->assertSame(1, substr_count($html, 'id="mapsight-embed-ssr"'));

        $payload = json_decode((string) $transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('req-1', $payload['requestId'] ?? null);
        $this->assertSame('assets-9', $payload['assetVersion'] ?? null);
        $this->assertSame('req-1', $transport->requests[0]->headers['X-Request-Id'] ?? null);
        $this->assertSame(2.0, $transport->requests[0]->timeoutSeconds);
        $this->assertSame(0.1, $transport->requests[0]->connectTimeoutSeconds);
    }

    public function test_ssr_failure_falls_back_to_client_only(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrUnavailable('connection refused'));
        $result = $this->renderer($transport)->render(new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-fallback',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
        ));

        $this->assertSame(SsrOutcome::SkippedError, $result->ssr);
        $this->assertSame('connection refused', $result->ssrReason);
        $this->assertStringContainsString('<!-- mapsight-ssr-skipped -->', $result->html);
        $this->assertStringContainsString('<div id="mapsight-embed-fallback"></div>', $result->html);
        $this->assertStringNotContainsString('data-dehydrated-state', $result->html);
    }

    public function test_passes_split_timeouts_to_transport(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queueV1('<div id="mapsight-embed-timeouts"></div>');
        $client = new SsrClient(
            ssrUrl: 'http://ssr:4123',
            timeoutSeconds: 3.0,
            connectTimeoutSeconds: 0.05,
            transport: $transport,
        );
        (new Renderer($client))->render(new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-timeouts',
            config: [],
        ));

        $this->assertSame(3.0, $transport->requests[0]->timeoutSeconds);
        $this->assertSame(0.05, $transport->requests[0]->connectTimeoutSeconds);
    }

    public function test_open_circuit_skips_transport_until_cooldown(): void
    {
        $clock = new class {
            public float $now = 1000.0;
        };
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrUnavailable('sidecar down'));
        $transport->queue(new SsrUnavailable('sidecar down'));
        $transport->queue(new SsrUnavailable('sidecar down'));
        $renderer = $this->renderer(
            $transport,
            breaker: new ProcessSsrCircuitBreaker(2, 10.0, fn () => $clock->now),
        );
        $request = new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-breaker',
            config: [],
        );

        $renderer->render($request);
        $renderer->render($request);
        $skipped = $renderer->render($request);

        $this->assertCount(2, $transport->requests);
        $this->assertSame(SsrOutcome::SkippedBreaker, $skipped->ssr);
        $this->assertStringContainsString('<!-- mapsight-ssr-skipped -->', $skipped->html);

        $clock->now = 1010.0;
        $afterCooldown = $renderer->render($request);
        $this->assertCount(3, $transport->requests);
        $this->assertSame(SsrOutcome::SkippedError, $afterCooldown->ssr);
    }

    public function test_cache_hit_skips_node_and_does_not_mark_skipped(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queueV1('<div id="mapsight-embed-cache"></div>', ['app' => ['n' => 1]]);
        $transport->queueV1('<div id="mapsight-embed-cache"></div>', ['app' => ['n' => 2]]);
        $cache = new ArraySsrResultCache();
        $renderer = $this->renderer($transport, $cache);
        $request = new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-cache',
            config: ['enableList' => true],
            assetVersion: 'assets-1',
            locale: 'de',
            deviceClass: 'desktop',
        );

        $first = $renderer->render($request);
        $second = $renderer->render($request);

        $this->assertCount(1, $transport->requests);
        $payload = json_decode((string) $transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('de', $payload['options']['locale'] ?? null);
        $this->assertSame('desktop', $payload['options']['deviceClass'] ?? null);
        $this->assertSame(SsrOutcome::Rendered, $first->ssr);
        $this->assertSame(SsrOutcome::Cached, $second->ssr);
        $this->assertSame($first->containerHtml, $second->containerHtml);
        $this->assertStringNotContainsString('mapsight-ssr-skipped', $second->html);

        $cache->flush();
        $third = $renderer->render($request);
        $this->assertCount(2, $transport->requests);
        $this->assertSame(SsrOutcome::Rendered, $third->ssr);
    }

    public function test_cache_key_ignores_config_key_order(): void
    {
        $left = new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-key',
            config: ['b' => 2, 'a' => 1],
            assetVersion: 'v1',
            locale: 'de',
            deviceClass: 'mobile',
        );
        $right = new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-key',
            config: ['a' => 1, 'b' => 2],
            assetVersion: 'v1',
            locale: 'de',
            deviceClass: 'mobile',
        );

        $this->assertSame(SsrCacheKey::for($left, null), SsrCacheKey::for($right, null));
    }

    public function test_ssr_payload_forwards_request_url_and_share_params(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queueV1('<div id="mapsight-embed-url"></div>');
        $this->renderer($transport)->render(new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-url',
            config: [],
            requestUrl: '/map/?module=traffic&utm_source=x',
        ));

        $payload = json_decode((string) $transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/map/?module=traffic', $payload['options']['requestUrl'] ?? null);
    }

    public function test_utm_and_click_ids_do_not_change_the_cache_key(): void
    {
        $client = new SsrClient(ssrUrl: 'http://ssr:4123', transport: new FakeSsrTransport());
        $plain = $client->normalizeRequestUrl('/map');
        $utm = $client->normalizeRequestUrl('/map?utm_source=x');
        $feature = $client->normalizeRequestUrl('/map?feature=1');
        $featureClick = $client->normalizeRequestUrl('/map?feature=1&fbclid=z');

        $this->assertSame($plain, $utm);
        $this->assertSame($feature, $featureClick);
        $this->assertNotSame($plain, $feature);
    }

    public function test_ssr_payload_forwards_page_origin_and_og_image(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queueV1('<div id="mapsight-embed-origin"></div>');
        $this->renderer($transport)->render(new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-origin',
            config: [],
            requestUrl: '/map?feature=poi-1',
            pageOrigin: 'https://www.example.com',
            ogImage: 'https://www.example.com/plan/img/og-default.png',
        ));

        $payload = json_decode((string) $transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://www.example.com', $payload['options']['pageOrigin'] ?? null);
        $this->assertSame(
            'https://www.example.com/plan/img/og-default.png',
            $payload['options']['ogImage'] ?? null,
        );
        $this->assertSame('/map?feature=poi-1', $payload['options']['requestUrl'] ?? null);
    }

    public function test_render_exposes_page_meta_when_share_param_is_on_request_url(): void
    {
        $meta = [
            'title' => 'Town Hall',
            'description' => 'An example place.',
            'canonicalUrl' => 'https://www.example.com/map?feature=poi-1',
            'og' => [
                'title' => 'Town Hall',
                'description' => 'An example place.',
                'url' => 'https://www.example.com/map?feature=poi-1',
                'type' => 'place',
                'image' => 'https://www.example.com/plan/img/og-default.png',
            ],
            'jsonLd' => ['@type' => 'Place', 'name' => 'Town Hall'],
        ];
        $transport = new FakeSsrTransport();
        $transport->queueV1('<div id="mapsight-embed-meta"></div>', [], $meta);
        $transport->queueV1('<div id="mapsight-embed-meta"></div>', [], $meta);
        $transport->queueV1('<div id="mapsight-embed-meta"></div>', [], $meta);
        $renderer = $this->renderer($transport);

        $withFeature = $renderer->render(new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-meta',
            config: [],
            requestUrl: '/map?feature=poi-1',
            pageOrigin: 'https://www.example.com',
        ));
        $withModule = $renderer->render(new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-meta',
            config: [],
            requestUrl: '/plan/?module=parking',
            pageOrigin: 'https://www.example.com',
        ));
        $withoutShareable = $renderer->render(new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-meta',
            config: [],
            requestUrl: '/map',
            pageOrigin: 'https://www.example.com',
        ));

        $this->assertSame('Town Hall', $withFeature->pageMeta?->title);
        $this->assertStringContainsString('data-dehydrated-state=', $withFeature->html);
        $this->assertSame('Town Hall', $withModule->pageMeta?->title);
        $this->assertNull($withoutShareable->pageMeta);
        $this->assertStringContainsString('data-dehydrated-state=', $withoutShareable->html);
    }

    public function test_open_circuit_still_serves_warm_cache(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queueV1('<div id="mapsight-embed-warm"></div>', ['n' => 1]);
        $cache = new ArraySsrResultCache();
        $breaker = new ProcessSsrCircuitBreaker(1, 60.0);
        $renderer = $this->renderer($transport, $cache, $breaker);
        $request = new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-warm',
            config: [],
        );

        $warm = $renderer->render($request);
        $breaker->recordFailure();
        $this->assertFalse($breaker->allow());
        $served = $renderer->render($request);

        $this->assertCount(1, $transport->requests);
        $this->assertSame(SsrOutcome::Cached, $served->ssr);
        $this->assertSame($warm->containerHtml, $served->containerHtml);
        $this->assertStringNotContainsString('mapsight-ssr-skipped', $served->html);
    }

    public function test_client_errors_do_not_trip_the_breaker(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrClientError('SSR v1 error VALIDATION'));
        $transport->queue(new SsrClientError('SSR v1 error VALIDATION'));
        $transport->queue(new SsrClientError('SSR v1 error VALIDATION'));
        $renderer = $this->renderer(
            $transport,
            breaker: new ProcessSsrCircuitBreaker(2, 10.0),
        );
        $request = new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-client-error',
            config: [],
        );

        $renderer->render($request);
        $renderer->render($request);
        $third = $renderer->render($request);

        $this->assertCount(3, $transport->requests);
        $this->assertSame(SsrOutcome::SkippedError, $third->ssr);
    }

    public function test_script_nonce_and_json_encoded_urls(): void
    {
        $result = (new Renderer())->render(new EmbedRequest(
            preset: 'simpleMap',
            containerId: 'mapsight-embed-nonce',
            config: [],
            assetBase: '/x</script><script>alert(1)</script>',
            scriptNonce: 'abc-1',
        ));

        $this->assertStringContainsString('<script type="module" nonce="abc-1">', $result->html);
        $this->assertStringContainsString('\u003C/script\u003E', $result->bootScriptHtml);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $result->bootScriptHtml);
    }

    private function renderer(
        FakeSsrTransport $transport,
        ?ArraySsrResultCache $cache = null,
        ?ProcessSsrCircuitBreaker $breaker = null,
    ): Renderer {
        return new Renderer(new SsrClient(
            ssrUrl: 'http://ssr:4123',
            transport: $transport,
            resultCache: $cache,
            breaker: $breaker,
        ));
    }
}
