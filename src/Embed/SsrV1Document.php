<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Mapsight SSR API v1: JSON { v, html, state, pageMeta } → fragment + head DTO.
 */
final class SsrV1Document
{
    public static function fromResponse(string $body): SsrDocument
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('SSR v1 response is not JSON', 0, $e);
        }

        if (!is_array($data) || ($data['v'] ?? null) !== 1) {
            throw new \RuntimeException('SSR v1 response missing v=1');
        }

        if (isset($data['error'])) {
            $code = is_array($data['error']) ? (string) ($data['error']['code'] ?? 'RENDER_FAILED') : 'RENDER_FAILED';
            throw new \RuntimeException('SSR v1 error ' . $code);
        }

        $html = $data['html'] ?? null;
        if (!is_string($html) || trim($html) === '') {
            throw new \RuntimeException('SSR v1 html missing');
        }

        if (!array_key_exists('state', $data)) {
            throw new \RuntimeException('SSR v1 state missing');
        }

        return new SsrDocument(
            self::withDehydratedState($html, $data['state']),
            PlacePageMeta::tryFrom($data['pageMeta'] ?? null),
        );
    }

    public static function containerHtmlFromResponse(string $body): string
    {
        return self::fromResponse($body)->html;
    }

    public static function withDehydratedState(string $html, mixed $state): string
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $escaped = htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = trim($html);
        $end = self::openingTagEnd($html);
        $opening = substr($html, 0, $end + 1);

        $opening = preg_replace(
            '/\sdata-dehydrated-state=(?:"[^"]*"|\'[^\']*\')/',
            '',
            $opening,
        ) ?? $opening;
        if (!str_ends_with($opening, '>')) {
            throw new \RuntimeException('SSR v1 html has no opening element');
        }

        $opening = substr($opening, 0, -1) . ' data-dehydrated-state="' . $escaped . '">';

        return $opening . substr($html, $end + 1);
    }

    /** First `>` that is not inside a quoted attribute (OSM attribution has raw `>`). */
    private static function openingTagEnd(string $html): int
    {
        if ($html === '' || $html[0] !== '<') {
            throw new \RuntimeException('SSR v1 html has no opening element');
        }

        $quote = null;
        $length = strlen($html);
        for ($i = 1; $i < $length; $i++) {
            $ch = $html[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if ($ch === '>') {
                return $i;
            }
        }

        throw new \RuntimeException('SSR v1 html has no opening element');
    }
}
