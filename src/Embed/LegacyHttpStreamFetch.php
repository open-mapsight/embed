<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * PHP < 8.4 streams only. Loaded only when
 * {@see http_get_last_response_headers()} is missing, so PHP 8.5 never
 * compiles the deprecated `$http_response_header` identifier.
 */
final class LegacyHttpStreamFetch
{
    /**
     * @param array<string, mixed> $http
     * @return array{0: string, 1: list<string>}
     */
    public static function get(string $url, array $http): array
    {
        $body = @file_get_contents($url, false, stream_context_create(['http' => $http]));
        if ($body === false) {
            throw new SsrUnavailable('SSR request failed');
        }

        return [$body, $http_response_header];
    }
}
