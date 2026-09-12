<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\PageMetaTags;
use OpenMapsight\Embed\PlacePageMeta;
use OpenMapsight\Embed\PlacePageMetaOg;
use PHPUnit\Framework\TestCase;

final class PageMetaTagsTest extends TestCase
{
    public function test_html_emits_canonical_og_and_json_ld(): void
    {
        $html = PageMetaTags::html(self::sample());

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://www.example.com/map?feature=poi-1">',
            $html,
        );
        $this->assertStringContainsString('name="description" content="An example place."', $html);
        $this->assertStringContainsString('property="og:title" content="Town Hall"', $html);
        $this->assertStringContainsString('property="og:type" content="place"', $html);
        $this->assertStringContainsString(
            'property="og:image" content="https://www.example.com/plan/img/og-default.png"',
            $html,
        );
        $this->assertStringContainsString('<script type="application/ld+json">', $html);
        $this->assertStringContainsString('"@type":"Place"', $html);
        $this->assertSame('Town Hall', PageMetaTags::title(self::sample(), 'Artikel'));
        $this->assertSame('Artikel', PageMetaTags::title(null, 'Artikel'));
        $this->assertSame('', PageMetaTags::html(null));
    }

    public function test_try_from_fails_open_on_garbage(): void
    {
        $this->assertNull(PlacePageMeta::tryFrom(null));
        $this->assertNull(PlacePageMeta::tryFrom(['title' => 'only']));
        $this->assertNull(PlacePageMeta::tryFrom('Town Hall'));
    }

    public function test_try_from_rejects_unknown_og_type_and_non_http_urls(): void
    {
        $base = [
            'title' => 'Town Hall',
            'description' => 'An example place.',
            'canonicalUrl' => 'https://www.example.com/map?feature=poi-1',
            'og' => [
                'title' => 'Town Hall',
                'description' => 'An example place.',
                'url' => 'https://www.example.com/map?feature=poi-1',
                'type' => 'article',
                'image' => 'https://www.example.com/plan/img/og-default.png',
            ],
            'jsonLd' => ['@type' => 'Place'],
        ];
        $this->assertNull(PlacePageMeta::tryFrom($base));

        $base['og']['type'] = 'place';
        $base['canonicalUrl'] = '/map?feature=poi-1';
        $this->assertNull(PlacePageMeta::tryFrom($base));

        $base['canonicalUrl'] = 'https://www.example.com/map?feature=poi-1';
        $base['og']['image'] = 'javascript:alert(1)';
        $this->assertNull(PlacePageMeta::tryFrom($base));
    }

    private static function sample(): PlacePageMeta
    {
        return PlacePageMeta::tryFrom([
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
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@type' => 'Place',
                'name' => 'Town Hall',
            ],
        ]) ?? throw new \RuntimeException('sample meta');
    }
}
