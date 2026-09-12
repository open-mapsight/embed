<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\ArraySsrResultCache;
use OpenMapsight\Embed\SsrClient;
use OpenMapsight\Embed\SsrDocument;
use OpenMapsight\Embed\SsrHttpResponse;
use OpenMapsight\Embed\SsrPublish;
use OpenMapsight\Embed\SsrUnavailable;
use OpenMapsight\Embed\Testing\FakeSsrTransport;
use PHPUnit\Framework\TestCase;

final class SsrPublishTest extends TestCase
{
    public function test_purges_sidecar_then_flushes_php_fragments(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrHttpResponse(
            200,
            'application/json',
            '["doc::https://example.test/schools.geojson"]',
        ));
        $cache = new ArraySsrResultCache();
        $cache->set('k1', new SsrDocument('<div data-dehydrated-state="{}"></div>'), 60);
        $hook = new SsrPublish($this->client($transport, $cache));

        $result = $hook->purge(['https://example.test/schools.geojson']);

        $this->assertTrue($result->sidecarPurged);
        $this->assertSame(['doc::https://example.test/schools.geojson'], $result->deletedKeys);
        $this->assertCount(1, $transport->requests);
        $this->assertSame('http://ssr:4123/purge', $transport->requests[0]->url);
        $this->assertSame(
            ['urls' => ['https://example.test/schools.geojson']],
            json_decode((string) $transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertNull($cache->get('k1'));
    }

    public function test_omitted_urls_clears_sidecar_and_still_flushes_php(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrHttpResponse(200, 'application/json', '["doc::all"]'));
        $cache = new ArraySsrResultCache();
        $cache->set('k1', new SsrDocument('<div></div>'), 60);

        $result = (new SsrPublish($this->client($transport, $cache)))->purge();

        $this->assertTrue($result->sidecarPurged);
        $this->assertSame(['doc::all'], $result->deletedKeys);
        $this->assertSame('{}', $transport->requests[0]->body);
        $this->assertNull($cache->get('k1'));
    }

    public function test_sidecar_failure_does_not_flush_php(): void
    {
        $transport = new FakeSsrTransport();
        $transport->queue(new SsrUnavailable('connection refused'));
        $cache = new ArraySsrResultCache();
        $cache->set('k1', new SsrDocument('<div></div>'), 60);

        $result = (new SsrPublish($this->client($transport, $cache)))
            ->purge(['https://example.test/a.geojson']);

        $this->assertFalse($result->sidecarPurged);
        $this->assertSame([], $result->deletedKeys);
        $this->assertNotNull($cache->get('k1'));
    }

    public function test_blank_urls_are_not_a_purge_all(): void
    {
        $transport = new FakeSsrTransport();
        $cache = new ArraySsrResultCache();
        $cache->set('k1', new SsrDocument('<div></div>'), 60);

        try {
            (new SsrPublish($this->client($transport, $cache)))->purge(['']);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('only empty urls', $e->getMessage());
        }

        $this->assertSame([], $transport->requests);
        $this->assertNotNull($cache->get('k1'));
    }

    private function client(FakeSsrTransport $transport, ArraySsrResultCache $cache): SsrClient
    {
        return new SsrClient(
            ssrUrl: 'http://ssr:4123',
            transport: $transport,
            resultCache: $cache,
        );
    }
}
