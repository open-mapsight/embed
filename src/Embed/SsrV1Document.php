<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Mapsight SSR API v1: JSON { v, html, state, pageMeta } → fragment + head DTO.
 */
final class SsrV1Document
{
    public static function fromResponse(
        string $body,
        string $containerId,
        int $maxStateBytes = 262144,
    ): SsrDocument {
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SsrClientError('SSR v1 response is not JSON', 0, $e);
        }

        if (!is_array($data) || ($data['v'] ?? null) !== SsrContract::VERSION) {
            throw new SsrClientError('SSR v1 response missing v=' . SsrContract::VERSION);
        }

        if (isset($data['error'])) {
            $code = 'RENDER_FAILED';
            if (is_array($data['error']) && isset($data['error']['code']) && is_string($data['error']['code'])) {
                $code = $data['error']['code'];
            }
            throw new SsrClientError('SSR v1 error ' . $code);
        }

        $html = $data['html'] ?? null;
        if (!is_string($html) || trim($html) === '') {
            throw new SsrClientError('SSR v1 html missing');
        }

        if (!array_key_exists('state', $data)) {
            throw new SsrClientError('SSR v1 state missing');
        }

        return new SsrDocument(
            self::withDehydratedState($html, $data['state'], $containerId, $maxStateBytes),
            PlacePageMeta::tryFrom($data['pageMeta'] ?? null),
        );
    }

    public static function containerHtmlFromResponse(
        string $body,
        string $containerId = 'mapsight-embed-1',
        int $maxStateBytes = 262144,
    ): string {
        return self::fromResponse($body, $containerId, $maxStateBytes)->html;
    }

    public static function withDehydratedState(
        string $html,
        mixed $state,
        string $containerId,
        int $maxStateBytes = 262144,
    ): string {
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > $maxStateBytes) {
            throw new SsrClientError('SSR v1 state exceeds size cap');
        }
        $escaped = htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = trim($html);
        $end = self::openingTagEnd($html);
        $opening = substr($html, 0, $end + 1);
        self::assertContainerId($opening, $containerId);

        $opening = preg_replace(
            '/\sdata-dehydrated-state=(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/',
            '',
            $opening,
        ) ?? $opening;
        if (!str_ends_with($opening, '>')) {
            throw new SsrClientError('SSR v1 html has no opening element');
        }

        $opening = substr($opening, 0, -1) . ' data-dehydrated-state="' . $escaped . '">';

        return $opening . substr($html, $end + 1);
    }

    /** First `>` that is not inside a quoted attribute (OSM attribution has raw `>`). */
    private static function openingTagEnd(string $html): int
    {
        if ($html === '' || preg_match('/^<[A-Za-z]/', $html) !== 1) {
            throw new SsrClientError('SSR v1 html has no opening element');
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

        throw new SsrClientError('SSR v1 html has no opening element');
    }

    private static function assertContainerId(string $opening, string $containerId): void
    {
        if (preg_match('/\sid=(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $opening, $matches) !== 1) {
            throw new SsrClientError('SSR v1 html root id does not match containerId');
        }
        $quoted = $matches[1];
        $single = $matches[2] ?? '';
        $unquoted = $matches[3] ?? '';
        $id = $quoted !== '' ? $quoted : ($single !== '' ? $single : $unquoted);
        if ($id !== $containerId) {
            throw new SsrClientError('SSR v1 html root id does not match containerId');
        }
    }
}
