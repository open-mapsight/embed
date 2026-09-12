<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\SsrClientError;
use OpenMapsight\Embed\SsrV1Document;
use PHPUnit\Framework\TestCase;

final class SsrV1DocumentTest extends TestCase
{
    public function test_injects_json_state_into_dehydrated_attribute(): void
    {
        $html = SsrV1Document::containerHtmlFromResponse(json_encode([
            'v' => 1,
            'html' => '<div id="mapsight-embed-1" class="mapsight-embed"></div>',
            'state' => ['app' => ['title' => 'ok & "x"']],
            'meta' => ['preset' => 'infosite'],
        ], JSON_THROW_ON_ERROR));

        $this->assertStringContainsString('id="mapsight-embed-1"', $html);
        $this->assertSame(
            ['app' => ['title' => 'ok & "x"']],
            $this->dehydratedState($html),
        );
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_from_response_keeps_page_meta_and_fails_open_when_incomplete(): void
    {
        $document = SsrV1Document::fromResponse(json_encode([
            'v' => 1,
            'html' => '<div id="x" class="mapsight-embed"></div>',
            'state' => ['app' => ['ssr' => 'v1']],
            'pageMeta' => [
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
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('Town Hall', $document->pageMeta?->title);
        $this->assertSame(
            'https://www.example.com/map?feature=poi-1',
            $document->pageMeta?->canonicalUrl,
        );
        $this->assertStringContainsString('data-dehydrated-state=', $document->html);

        $withoutMeta = SsrV1Document::fromResponse(json_encode([
            'v' => 1,
            'html' => '<div id="x" class="mapsight-embed"></div>',
            'state' => ['app' => ['ssr' => 'v1']],
            'pageMeta' => ['title' => 'incomplete'],
        ], JSON_THROW_ON_ERROR));
        $this->assertNull($withoutMeta->pageMeta);
    }

    public function test_replaces_state_already_on_the_fragment(): void
    {
        $html = SsrV1Document::containerHtmlFromResponse(json_encode([
            'v' => 1,
            'html' => '<div id="x" data-dehydrated-state="{&quot;app&quot;:{&quot;ssr&quot;:&quot;stub&quot;}}"></div>',
            'state' => ['app' => ['ssr' => 'v1']],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(['app' => ['ssr' => 'v1']], $this->dehydratedState($html));
        $this->assertStringNotContainsString('stub', $html);
    }

    public function test_replaces_node_state_when_json_attribute_contains_gt(): void
    {
        $attribution = '<a href="https://www.openstreetmap.org/copyright" rel="external" target="_blank">OpenStreetMap-Mitwirkende</a>.';
        $nodeState = json_encode([
            'map' => ['layers' => ['street' => ['attribution' => $attribution]]],
        ], JSON_THROW_ON_ERROR);
        $nodeHtml = '<div id="x" class="mapsight-embed" data-dehydrated-state=\''
            . $nodeState
            . '\'><p>shell</p></div>';

        $html = SsrV1Document::containerHtmlFromResponse(json_encode([
            'v' => 1,
            'html' => $nodeHtml,
            'state' => [
                'map' => ['layers' => ['street' => ['attribution' => $attribution]]],
                'app' => ['ssr' => 'v1'],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(
            [
                'map' => ['layers' => ['street' => ['attribution' => $attribution]]],
                'app' => ['ssr' => 'v1'],
            ],
            $this->dehydratedState($html),
        );
        $this->assertSame(1, substr_count($html, 'data-dehydrated-state='));
        $this->assertStringContainsString('<p>shell</p>', $html);
    }

    /** @return array<string, mixed> */
    private function dehydratedState(string $html): array
    {
        $this->assertSame(1, preg_match('/data-dehydrated-state="([^"]*)"/', $html, $matches));
        $decoded = html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $state = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($state);

        return $state;
    }

    public function test_rejects_error_payload(): void
    {
        $this->expectException(SsrClientError::class);

        SsrV1Document::containerHtmlFromResponse(json_encode([
            'v' => 1,
            'error' => ['code' => 'RENDER_FAILED', 'message' => 'render failed'],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_rejects_html_that_starts_with_a_comment(): void
    {
        $this->expectException(SsrClientError::class);
        $this->expectExceptionMessage('SSR v1 html has no opening element');

        SsrV1Document::containerHtmlFromResponse(json_encode([
            'v' => 1,
            'html' => '<!-- ssr --><div id="c"></div>',
            'state' => ['a' => 1],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_accepts_utf8_bom_before_json(): void
    {
        $html = SsrV1Document::containerHtmlFromResponse("\xEF\xBB\xBF" . json_encode([
            'v' => 1,
            'html' => '<div id="mapsight-embed-1" class="mapsight-embed"></div>',
            'state' => ['app' => ['ssr' => 'v1']],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(['app' => ['ssr' => 'v1']], $this->dehydratedState($html));
    }

    public function test_rejects_raw_html_body(): void
    {
        $this->expectException(SsrClientError::class);
        $this->expectExceptionMessage('SSR v1 response is not JSON');

        SsrV1Document::fromResponse('<div id="c" data-dehydrated-state="{}"></div>');
    }
}
