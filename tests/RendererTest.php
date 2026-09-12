<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\ArraySsrResultCache;
use OpenMapsight\Embed\EmbedRequest;
use OpenMapsight\Embed\PlacePageMeta;
use OpenMapsight\Embed\PlacePageMetaOg;
use OpenMapsight\Embed\ProcessSsrCircuitBreaker;
use OpenMapsight\Embed\Renderer;
use OpenMapsight\Embed\SsrCacheKey;
use OpenMapsight\Embed\SsrClientError;
use OpenMapsight\Embed\SsrDocument;
use OpenMapsight\Embed\SsrTransport;
use OpenMapsight\Embed\SsrUnavailable;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    public function test_rejects_connect_timeout_longer_than_total(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-bad-timeout',
            config: [],
            ssrTimeoutSeconds: 0.05,
            ssrConnectTimeoutSeconds: 0.1,
        );
    }

    public function test_client_only_emits_css_container_and_mount_boot(): void
    {
        $html = (new Renderer())->render(new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-demo',
            config: [
                'imagesUrl' => '/mapsight/plan/img/',
                'enableMap' => true,
                'enableList' => true,
                'enableTagSwitcher' => true,
                'startCoordinates' => [10.53, 52.27],
                'startZoom' => 12,
                'view' => 'desktop',
            ],
        ));

        $this->assertStringContainsString(
            'href="/mapsight/plan/assets/mapsight.css"',
            $html,
        );
        $this->assertStringContainsString(
            'id="mapsight-embed-demo"',
            $html,
        );
        $this->assertStringContainsString(
            'import {mountEmbed} from "/mapsight/plan/assets/embed.js"',
            $html,
        );
        $this->assertStringContainsString(
            'import {infosite} from "/mapsight/plan/assets/infosite.js"',
            $html,
        );
        $this->assertStringContainsString('mountEmbed("mapsight-embed-demo"', $html);
        $this->assertStringContainsString('infosite(', $html);
        $this->assertStringContainsString('"enableList":true', $html);
        $this->assertStringContainsString('"view":"desktop"', $html);
        $this->assertStringContainsString('<div id="mapsight-embed-demo"></div>', $html);
        $this->assertStringNotContainsString('data-dehydrated-state', $html);
        $this->assertStringNotContainsString('ms3-', $html);
        $this->assertStringNotContainsString('stadtplan', $html);
        $this->assertStringNotContainsString('mapsight-ssr-skipped', $html);
    }

    public function test_host_supplies_container_class_on_empty_mount(): void
    {
        $html = (new Renderer())->render(new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-demo',
            config: [],
            containerClassName: 'host-embed',
        ));

        $this->assertStringContainsString('<div id="mapsight-embed-demo" class="host-embed"></div>', $html);
        $this->assertStringNotContainsString('ms3-', $html);
    }

    public function test_ssr_success_injects_dehydrated_fragment_and_boot(): void
    {
        $transport = new class implements SsrTransport {
            public function postJson(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): SsrDocument {
                TestCase::assertSame('http://ssr:4123/v1/render', $url);
                TestCase::assertSame(2.0, $timeoutSeconds);
                TestCase::assertSame(0.1, $connectTimeoutSeconds);
                TestCase::assertSame(1, $payload['v'] ?? null);
                TestCase::assertSame('req-1', $payload['requestId'] ?? null);
                TestCase::assertSame('assets-9', $payload['assetVersion'] ?? null);
                TestCase::assertSame('application/json', $headers['Accept'] ?? null);
                TestCase::assertSame('req-1', $headers['X-Request-Id'] ?? null);
                TestCase::assertSame('assets-9', $headers['X-Mapsight-Asset-Version'] ?? null);
                TestCase::assertSame('infosite', $payload['preset'] ?? null);
                TestCase::assertSame(
                    'mapsight-embed-ssr',
                    $payload['options']['containerId'] ?? null,
                );

                return new SsrDocument('<div id="mapsight-embed-ssr" class="mapsight-embed" data-dehydrated-state="{&quot;app&quot;:{&quot;title&quot;:&quot;ok&quot;}}"></div>');
            }
        };

        $html = (new Renderer($transport))->render(new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-ssr',
            config: ['imagesUrl' => '/mapsight/plan/img/', 'enableMap' => true],
            ssrUrl: 'http://ssr:4123',
            requestId: 'req-1',
            assetVersion: 'assets-9',
        ));

        $this->assertStringContainsString('data-dehydrated-state=', $html);
        $this->assertStringContainsString(
            'href="/mapsight/plan/assets/mapsight.css?v=assets-9"',
            $html,
        );
        $this->assertStringContainsString(
            'import {mountEmbed} from "/mapsight/plan/assets/embed.js?v=assets-9"',
            $html,
        );
        $this->assertStringContainsString(
            'import {infosite} from "/mapsight/plan/assets/infosite.js?v=assets-9"',
            $html,
        );
        $this->assertStringContainsString('mountEmbed("mapsight-embed-ssr"', $html);
        $this->assertStringNotContainsString('mapsight-ssr-skipped', $html);
        $this->assertSame(1, substr_count($html, 'id="mapsight-embed-ssr"'));
    }

    public function test_ssr_failure_falls_back_to_client_only(): void
    {
        $transport = new class implements SsrTransport {
            public function postJson(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): SsrDocument {
                throw new \RuntimeException('connection refused');
            }
        };

        $html = (new Renderer($transport))->render(new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-fallback',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
        ));

        $this->assertStringContainsString('<!-- mapsight-ssr-skipped -->', $html);
        $this->assertStringContainsString('<div id="mapsight-embed-fallback"></div>', $html);
        $this->assertStringNotContainsString('data-dehydrated-state', $html);
        $this->assertStringNotContainsString('ms3-', $html);
        $this->assertStringContainsString(
            'import {mountEmbed} from "/mapsight/plan/assets/embed.js"',
            $html,
        );
        $this->assertStringContainsString('mountEmbed("mapsight-embed-fallback"', $html);
    }

    public function test_passes_split_timeouts_to_transport(): void
    {
        $transport = new class implements SsrTransport {
            public float $timeout = 0;
            public float $connect = 0;

            public function postJson(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): SsrDocument {
                $this->timeout = $timeoutSeconds;
                $this->connect = $connectTimeoutSeconds;

                return new SsrDocument('<div id="mapsight-embed-timeouts" class="mapsight-embed" data-dehydrated-state="{}"></div>');
            }
        };

        (new Renderer($transport))->render(new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-timeouts',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
            ssrTimeoutSeconds: 3.0,
            ssrConnectTimeoutSeconds: 0.05,
        ));

        $this->assertSame(3.0, $transport->timeout);
        $this->assertSame(0.05, $transport->connect);
    }

    public function test_open_circuit_skips_transport_until_cooldown(): void
    {
        $clock = new class {
            public float $now = 1000.0;
        };
        $transport = new class implements SsrTransport {
            public int $calls = 0;

            public function postJson(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): SsrDocument {
                $this->calls++;
                throw new SsrUnavailable('sidecar down');
            }
        };
        $renderer = new Renderer(
            $transport,
            new ProcessSsrCircuitBreaker(2, 10.0, fn () => $clock->now),
        );
        $request = new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-breaker',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
        );

        $first = $renderer->render($request);
        $second = $renderer->render($request);
        $skipped = $renderer->render($request);

        $this->assertSame(2, $transport->calls);
        $this->assertStringContainsString('<!-- mapsight-ssr-skipped -->', $first);
        $this->assertStringContainsString('<!-- mapsight-ssr-skipped -->', $second);
        $this->assertStringContainsString('<!-- mapsight-ssr-skipped -->', $skipped);

        $clock->now = 1010.0;
        $afterCooldown = $renderer->render($request);
        $this->assertSame(3, $transport->calls);
        $this->assertStringContainsString('<!-- mapsight-ssr-skipped -->', $afterCooldown);
    }

    public function test_cache_hit_skips_node_and_does_not_mark_skipped(): void
    {
        $transport = new class implements SsrTransport {
            public int $calls = 0;

            /** @var array<string, mixed>|null */
            public ?array $payload = null;

            public function postJson(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): SsrDocument {
                $this->calls++;
                $this->payload = $payload;

                return new SsrDocument('<div id="mapsight-embed-cache" class="mapsight-embed" data-dehydrated-state="{&quot;app&quot;:{&quot;n&quot;:'
                    . $this->calls
                    . '}}"></div>');
            }
        };
        $cache = new ArraySsrResultCache();
        $renderer = new Renderer($transport, new ProcessSsrCircuitBreaker(), $cache);
        $request = new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-cache',
            config: ['enableList' => true, 'imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
            assetVersion: 'assets-1',
            locale: 'de',
            deviceClass: 'desktop',
        );

        $first = $renderer->render($request);
        $second = $renderer->render($request);

        $this->assertSame(1, $transport->calls);
        $this->assertSame('de', $transport->payload['options']['locale'] ?? null);
        $this->assertSame('desktop', $transport->payload['options']['deviceClass'] ?? null);
        $this->assertStringContainsString('data-dehydrated-state=', $first);
        $this->assertSame($first, $second);
        $this->assertStringNotContainsString('mapsight-ssr-skipped', $second);

        $cache->flush();
        $third = $renderer->render($request);
        $this->assertSame(2, $transport->calls);
        $this->assertStringContainsString('data-dehydrated-state=', $third);
    }

    public function test_cache_key_ignores_config_key_order(): void
    {
        $left = new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-key',
            config: ['b' => 2, 'a' => 1],
            assetVersion: 'v1',
            locale: 'de',
            deviceClass: 'mobile',
        );
        $right = new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-key',
            config: ['a' => 1, 'b' => 2],
            assetVersion: 'v1',
            locale: 'de',
            deviceClass: 'mobile',
        );

        $this->assertSame(SsrCacheKey::for($left), SsrCacheKey::for($right));
    }

    public function test_ssr_payload_forwards_request_url(): void
    {
        $transport = new class implements SsrTransport {
            /** @var array<string, mixed>|null */
            public ?array $payload = null;

            public function postJson(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): SsrDocument {
                $this->payload = $payload;

                return new SsrDocument('<div id="mapsight-embed-url" class="mapsight-embed" data-dehydrated-state="{}"></div>');
            }
        };

        (new Renderer($transport))->render(new EmbedRequest(
            preset: 'stadtplan',
            containerId: 'mapsight-embed-url',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
            requestUrl: '/map/?module=baustellen-verkehr',
        ));

        $this->assertSame(
            '/map/?module=baustellen-verkehr',
            $transport->payload['options']['requestUrl'] ?? null,
        );
    }

    public function test_ssr_payload_forwards_locale_and_device_class(): void
    {
        $transport = new class implements SsrTransport {
            /** @var array<string, mixed>|null */
            public ?array $payload = null;

            public function postJson(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): SsrDocument {
                $this->payload = $payload;

                return new SsrDocument('<div id="mapsight-embed-locale" class="mapsight-embed" data-dehydrated-state="{}"></div>');
            }
        };

        (new Renderer($transport))->render(new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-locale',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
            locale: 'de',
            deviceClass: 'mobile',
        ));

        $this->assertSame('de', $transport->payload['options']['locale'] ?? null);
        $this->assertSame('mobile', $transport->payload['options']['deviceClass'] ?? null);
    }

    public function test_open_circuit_still_serves_warm_cache(): void
    {
        $transport = new class implements SsrTransport {
            public int $calls = 0;

            public function postJson(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): SsrDocument {
                $this->calls++;

                return new SsrDocument('<div id="mapsight-embed-warm" class="mapsight-embed" data-dehydrated-state="{&quot;n&quot;:1}"></div>');
            }
        };
        $cache = new ArraySsrResultCache();
        $breaker = new ProcessSsrCircuitBreaker(1, 60.0);
        $renderer = new Renderer($transport, $breaker, $cache);
        $request = new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-warm',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
        );

        $warm = $renderer->render($request);
        $breaker->recordFailure();
        $this->assertFalse($breaker->allow());

        $served = $renderer->render($request);

        $this->assertSame(1, $transport->calls);
        $this->assertSame($warm, $served);
        $this->assertStringNotContainsString('mapsight-ssr-skipped', $served);
        $this->assertStringContainsString('data-dehydrated-state=', $served);
    }

    public function test_client_errors_do_not_trip_the_breaker(): void
    {
        $transport = new class implements SsrTransport {
            public int $calls = 0;

            public function postJson(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): SsrDocument {
                $this->calls++;
                throw new SsrClientError('SSR v1 error VALIDATION');
            }
        };
        $renderer = new Renderer(
            $transport,
            new ProcessSsrCircuitBreaker(2, 10.0),
        );
        $request = new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-client-error',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
        );

        $renderer->render($request);
        $renderer->render($request);
        $third = $renderer->render($request);

        $this->assertSame(3, $transport->calls);
        $this->assertStringContainsString('<!-- mapsight-ssr-skipped -->', $third);
    }

    public function test_cache_key_includes_request_url(): void
    {
        $home = new EmbedRequest(
            preset: 'stadtplan',
            containerId: 'mapsight-embed-key',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            requestUrl: '/map/',
        );
        $verkehr = new EmbedRequest(
            preset: 'stadtplan',
            containerId: 'mapsight-embed-key',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            requestUrl: '/map/?module=baustellen-verkehr',
        );

        $this->assertNotSame(SsrCacheKey::for($home), SsrCacheKey::for($verkehr));
    }

    public function test_ssr_payload_forwards_page_origin_and_og_image(): void
    {
        $transport = new class implements SsrTransport {
            /** @var array<string, mixed>|null */
            public ?array $payload = null;

            public function postJson(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): SsrDocument {
                $this->payload = $payload;

                return new SsrDocument('<div id="mapsight-embed-origin" class="mapsight-embed" data-dehydrated-state="{}"></div>');
            }
        };

        (new Renderer($transport))->render(new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-origin',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
            requestUrl: '/map?feature=poi-1',
            pageOrigin: 'https://www.example.com',
            ogImage: 'https://www.example.com/plan/img/og-default.png',
        ));

        $this->assertSame(
            'https://www.example.com',
            $transport->payload['options']['pageOrigin'] ?? null,
        );
        $this->assertSame(
            'https://www.example.com/plan/img/og-default.png',
            $transport->payload['options']['ogImage'] ?? null,
        );
        $this->assertSame(
            '/map?feature=poi-1',
            $transport->payload['options']['requestUrl'] ?? null,
        );
    }

    public function test_render_document_exposes_page_meta_when_feature_or_module_is_on_request_url(): void
    {
        $meta = self::samplePageMeta();
        $transport = new class($meta) implements SsrTransport {
            public function __construct(private readonly PlacePageMeta $meta)
            {
            }

            public function postJson(
                string $url,
                array $payload,
                float $timeoutSeconds,
                array $headers = [],
                float $connectTimeoutSeconds = 0.1,
            ): SsrDocument {
                return new SsrDocument(
                    '<div id="mapsight-embed-meta" class="mapsight-embed" data-dehydrated-state="{}"></div>',
                    $this->meta,
                );
            }
        };

        $withFeature = (new Renderer($transport))->renderDocument(new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-meta',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
            requestUrl: '/map?feature=poi-1',
            pageOrigin: 'https://www.example.com',
        ));
        $withModule = (new Renderer($transport))->renderDocument(new EmbedRequest(
            preset: 'stadtplan',
            containerId: 'mapsight-embed-meta',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
            requestUrl: '/plan/?module=parken',
            pageOrigin: 'https://www.example.com',
        ));
        $withoutShareable = (new Renderer($transport))->renderDocument(new EmbedRequest(
            preset: 'infosite',
            containerId: 'mapsight-embed-meta',
            config: ['imagesUrl' => '/mapsight/plan/img/'],
            ssrUrl: 'http://ssr:4123',
            requestUrl: '/map',
            pageOrigin: 'https://www.example.com',
        ));

        $this->assertSame('Town Hall', $withFeature->pageMeta?->title);
        $this->assertStringContainsString('data-dehydrated-state=', $withFeature->html);
        $this->assertSame('Town Hall', $withModule->pageMeta?->title);
        $this->assertNull($withoutShareable->pageMeta);
        $this->assertStringContainsString('data-dehydrated-state=', $withoutShareable->html);
    }

    private static function samplePageMeta(): PlacePageMeta
    {
        return new PlacePageMeta(
            'Town Hall',
            'An example place.',
            'https://www.example.com/map?feature=poi-1',
            new PlacePageMetaOg(
                'Town Hall',
                'An example place.',
                'https://www.example.com/map?feature=poi-1',
                'place',
                'https://www.example.com/plan/img/og-default.png',
            ),
            [
                '@context' => 'https://schema.org',
                '@type' => 'Place',
                'name' => 'Town Hall',
            ],
        );
    }
}
