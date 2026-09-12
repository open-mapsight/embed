<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Selected-feature or Stadtplan-module document meta from sidecar `renderEnvelope()`.
 *
 * Fail-open: {@see tryFrom()} returns null when the payload is missing or incomplete.
 * `canonicalUrl` / `og.url` are the page permalink, never `schema.url`.
 */
final class PlacePageMeta
{
    /**
     * @param array<string, mixed> $jsonLd
     */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $canonicalUrl,
        public readonly PlacePageMetaOg $og,
        public readonly array $jsonLd,
    ) {
    }

    public static function tryFrom(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $title = self::nonEmptyString($value['title'] ?? null);
        $description = self::nonEmptyString($value['description'] ?? null);
        $canonicalUrl = self::nonEmptyString($value['canonicalUrl'] ?? null);
        $ogRaw = $value['og'] ?? null;
        $jsonLd = $value['jsonLd'] ?? null;
        if (
            $title === null
            || $description === null
            || $canonicalUrl === null
            || !is_array($ogRaw)
            || !is_array($jsonLd)
        ) {
            return null;
        }

        $ogTitle = self::nonEmptyString($ogRaw['title'] ?? null);
        $ogDescription = self::nonEmptyString($ogRaw['description'] ?? null);
        $ogUrl = self::nonEmptyString($ogRaw['url'] ?? null);
        $ogType = self::nonEmptyString($ogRaw['type'] ?? null);
        $ogImage = self::nonEmptyString($ogRaw['image'] ?? null);
        if (
            $ogTitle === null
            || $ogDescription === null
            || $ogUrl === null
            || $ogType === null
            || $ogImage === null
            || ($ogType !== 'place' && $ogType !== 'website')
        ) {
            return null;
        }

        return new self(
            $title,
            $description,
            $canonicalUrl,
            new PlacePageMetaOg($ogTitle, $ogDescription, $ogUrl, $ogType, $ogImage),
            $jsonLd,
        );
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
