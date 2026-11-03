<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * POST {ssrUrl}/v1/og → the card's bytes, via cURL when available, else PHP streams.
 */
final class NativeSsrOgImageTransport implements SsrOgImageTransport
{
    private const MAX_BODY_BYTES = 262144;

    /** Cards are a few dozen KB; anything larger is not a card. */
    public const MAX_IMAGE_BYTES = 4194304;

    public function postOgImage(
        string $url,
        array $payload,
        float $timeoutSeconds,
        array $headers = [],
        float $connectTimeoutSeconds = 0.1,
    ): ?SsrOgImage {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BODY_BYTES) {
            throw new \RuntimeException('SSR request body exceeds size cap');
        }

        if (function_exists('curl_init')) {
            [$status, $contentType, $body] = $this->postWithCurl($url, $json, $timeoutSeconds, $connectTimeoutSeconds, $headers);
        } else {
            [$status, $contentType, $body] = $this->postWithStreams($url, $json, $timeoutSeconds, $headers);
        }

        return self::finish($status, $contentType, $body);
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: ?string, 2: string}
     */
    private function postWithCurl(
        string $url,
        string $json,
        float $timeoutSeconds,
        float $connectTimeoutSeconds,
        array $headers,
    ): array {
        $headerLines = ['Content-Type: application/json'];
        $headers += ['Accept' => 'image/png, application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('SSR og request failed');
        }

        $body = '';
        $limited = false;
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function (mixed $handle, string $chunk) use (&$body, &$limited): int {
                // Keep one extra byte to let the shared response validation
                // reject the image while retaining HTTP status and headers.
                $remaining = self::MAX_IMAGE_BYTES + 1 - strlen($body);
                if (strlen($chunk) > $remaining) {
                    $body .= substr($chunk, 0, $remaining);
                    $limited = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($connectTimeoutSeconds * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeoutSeconds * 1000)),
        ]);

        $completed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $error = curl_error($handle);
        unset($handle);

        if ($completed === false && !$limited) {
            throw new \RuntimeException($error !== '' ? $error : 'SSR og request failed');
        }

        return [$status, is_string($contentType) && $contentType !== '' ? $contentType : null, $body];
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: ?string, 2: string}
     */
    private function postWithStreams(string $url, string $json, float $timeoutSeconds, array $headers): array
    {
        $headerLines = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
        ];
        $headers += ['Accept' => 'image/png, application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines) . "\r\n",
                'content' => $json,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        // Response headers come from the stream metadata: PHP 8.5 deprecates
        // $http_response_header, and http_get_last_response_headers() needs 8.4.
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new \RuntimeException('SSR og request failed');
        }

        try {
            $headers = stream_get_meta_data($stream)['wrapper_data'] ?? null;
            $body = stream_get_contents($stream, self::MAX_IMAGE_BYTES + 1);
            $timedOut = stream_get_meta_data($stream)['timed_out'] ?? false;
        } finally {
            fclose($stream);
        }

        if ($body === false || $timedOut) {
            throw new \RuntimeException('SSR og request failed');
        }

        $status = 0;
        $contentType = null;
        $contentLength = null;
        if (is_array($headers)) {
            if (is_string($headers[0] ?? null) && preg_match('/\s(\d{3})\s/', $headers[0], $matches) === 1) {
                $status = (int) $matches[1];
            }
            foreach ($headers as $line) {
                if (is_string($line) && stripos($line, 'Content-Type:') === 0) {
                    $contentType = trim(substr($line, strlen('Content-Type:')));
                }
                if (is_string($line) && preg_match('/^Content-Length:\s*([0-9]+)\s*$/i', $line, $matches) === 1) {
                    $contentLength = (int) $matches[1];
                }
            }
        }

        if (strlen($body) <= self::MAX_IMAGE_BYTES && $contentLength !== null && strlen($body) !== $contentLength) {
            throw new \RuntimeException('SSR og response is incomplete');
        }

        return [$status, $contentType, $body];
    }

    private static function finish(int $status, ?string $contentType, string $body): ?SsrOgImage
    {
        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('SSR og HTTP status ' . $status);
        }
        $type = strtolower(trim((string) strtok((string) $contentType, ';')));
        if (!str_starts_with($type, 'image/')) {
            throw new \RuntimeException('SSR og response Content-Type must be an image');
        }
        if ($body === '' || strlen($body) > self::MAX_IMAGE_BYTES) {
            throw new \RuntimeException('SSR og response has no usable image');
        }

        return new SsrOgImage($type, $body);
    }
}
