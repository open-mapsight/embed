<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Maps {@see PlacePageMeta} onto document-head fields the host page already owns.
 *
 * Thin PHP hosts can emit tags via {@see html()}. A CMS should set the
 * equivalent title / canonical / OG / JSON-LD APIs from {@see fields()}.
 */
final class PageMetaTags
{
    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     canonicalUrl: string,
     *     ogTitle: string,
     *     ogDescription: string,
     *     ogUrl: string,
     *     ogType: string,
     *     ogImage: string,
     *     jsonLd: array<string, mixed>
     * }|null
     */
    public static function fields(?PlacePageMeta $meta): ?array
    {
        if ($meta === null) {
            return null;
        }

        return [
            'title' => $meta->title,
            'description' => $meta->description,
            'canonicalUrl' => $meta->canonicalUrl,
            'ogTitle' => $meta->og->title,
            'ogDescription' => $meta->og->description,
            'ogUrl' => $meta->og->url,
            'ogType' => $meta->og->type,
            'ogImage' => $meta->og->image,
            'jsonLd' => $meta->jsonLd,
        ];
    }

    public static function title(?PlacePageMeta $meta, string $fallback): string
    {
        return $meta !== null ? $meta->title : $fallback;
    }

    /**
     * Canonical, Open Graph, and JSON-LD tags. Caller still sets `<title>`.
     */
    public static function html(?PlacePageMeta $meta): string
    {
        $fields = self::fields($meta);
        if ($fields === null) {
            return '';
        }

        $jsonLd = json_encode(
            $fields['jsonLd'],
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP,
        );

        return implode("\n", [
            '<link rel="canonical" href="' . self::escape($fields['canonicalUrl']) . '">',
            '<meta property="og:title" content="' . self::escape($fields['ogTitle']) . '">',
            '<meta property="og:description" content="' . self::escape($fields['ogDescription']) . '">',
            '<meta property="og:url" content="' . self::escape($fields['ogUrl']) . '">',
            '<meta property="og:type" content="' . self::escape($fields['ogType']) . '">',
            '<meta property="og:image" content="' . self::escape($fields['ogImage']) . '">',
            '<script type="application/ld+json">' . $jsonLd . '</script>',
        ]) . "\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
