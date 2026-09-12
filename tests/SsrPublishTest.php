<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\ArraySsrResultCache;
use OpenMapsight\Embed\SsrDocument;
use OpenMapsight\Embed\SsrPublish;
use OpenMapsight\Embed\SsrPurgeTransport;
use PHPUnit\Framework\TestCase;

final class SsrPublishTest extends TestCase
{
    public function test_purges_sidecar_then_flushes_php_fragments(): void
    {
        $transport = new class implements SsrPurgeTransport {
            public int $calls = 0;

            /** @var list<array{url: string, payload: array<string, mixed>}> */
            public array $requests = [];

            public function postPurge(
                string $url,
                array $payload,
                float $timeoutSeconds,
                float $connectTimeoutSeconds = 0.1,
            ): array {
                $this->calls++;
                $this->requests[] = ['url' => $url, 'payload' => $payload];

                return ['doc::https://example.test/schools.geojson'];
            }
        };
        $cache = new ArraySsrResultCache();
        $cache->set('k1', new SsrDocument('<div data-dehydrated-state="{}"></div>'));
        $hook = new SsrPublish('http://ssr:4123', $cache, $transport);

        $result = $hook->afterFeatureSourcePublish([
            'https://example.test/schools.geojson',
        ]);

        $this->assertTrue($result->sidecarPurged);
        $this->assertSame(['doc::https://example.test/schools.geojson'], $result->deletedKeys);
        $this->assertSame(1, $transport->calls);
        $this->assertSame('http://ssr:4123/purge', $transport->requests[0]['url'] ?? null);
        $this->assertSame(
            ['urls' => ['https://example.test/schools.geojson']],
            $transport->requests[0]['payload'] ?? null,
        );
        $this->assertNull($cache->get('k1'));
    }

    public function test_omitted_urls_clears_sidecar_and_still_flushes_php(): void
    {
        $transport = new class implements SsrPurgeTransport {
            /** @var array<string, mixed>|null */
            public ?array $payload = null;

            public function postPurge(
                string $url,
                array $payload,
                float $timeoutSeconds,
                float $connectTimeoutSeconds = 0.1,
            ): array {
                $this->payload = $payload;

                return ['doc::all'];
            }
        };
        $cache = new ArraySsrResultCache();
        $cache->set('k1', new SsrDocument('<div></div>'));

        $result = (new SsrPublish('http://ssr:4123', $cache, $transport))
            ->afterFeatureSourcePublish();

        $this->assertTrue($result->sidecarPurged);
        $this->assertSame(['doc::all'], $result->deletedKeys);
        $this->assertSame([], $transport->payload);
        $this->assertNull($cache->get('k1'));
    }

    public function test_sidecar_failure_does_not_flush_php(): void
    {
        $transport = new class implements SsrPurgeTransport {
            public function postPurge(
                string $url,
                array $payload,
                float $timeoutSeconds,
                float $connectTimeoutSeconds = 0.1,
            ): array {
                throw new \RuntimeException('connection refused');
            }
        };
        $cache = new ArraySsrResultCache();
        $cache->set('k1', new SsrDocument('<div></div>'));

        $result = (new SsrPublish('http://ssr:4123', $cache, $transport))
            ->afterFeatureSourcePublish(['https://example.test/a.geojson']);

        $this->assertFalse($result->sidecarPurged);
        $this->assertSame([], $result->deletedKeys);
        $this->assertNotNull($cache->get('k1'));
    }

    public function test_missing_sidecar_url_still_flushes_php(): void
    {
        $cache = new ArraySsrResultCache();
        $cache->set('k1', new SsrDocument('<div></div>'));

        $result = (new SsrPublish(null, $cache))->afterFeatureSourcePublish([
            'https://example.test/a.geojson',
        ]);

        $this->assertFalse($result->sidecarPurged);
        $this->assertSame([], $result->deletedKeys);
        $this->assertNull($cache->get('k1'));
    }

    public function test_blank_urls_are_not_a_purge_all(): void
    {
        $transport = new class implements SsrPurgeTransport {
            public int $calls = 0;

            public function postPurge(
                string $url,
                array $payload,
                float $timeoutSeconds,
                float $connectTimeoutSeconds = 0.1,
            ): array {
                $this->calls++;

                return [];
            }
        };
        $cache = new ArraySsrResultCache();
        $cache->set('k1', new SsrDocument('<div></div>'));

        try {
            (new SsrPublish('http://ssr:4123', $cache, $transport))
                ->afterFeatureSourcePublish(['']);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('only empty urls', $e->getMessage());
        }

        $this->assertSame(0, $transport->calls);
        $this->assertNotNull($cache->get('k1'));
    }
}
