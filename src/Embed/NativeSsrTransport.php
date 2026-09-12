<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Loopback/compose SSR POST via cURL when available, else PHP streams.
 */
final class NativeSsrTransport implements SsrTransport
{
    private const MAX_BODY_BYTES = 262144;

    public function postJson(
        string $url,
        array $payload,
        float $timeoutSeconds,
        array $headers = [],
        float $connectTimeoutSeconds = 0.1,
    ): SsrDocument {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SsrClientError('SSR request body is not JSON', 0, $e);
        }
        if (strlen($json) > self::MAX_BODY_BYTES) {
            throw new SsrClientError('SSR request body exceeds size cap');
        }

        if (function_exists('curl_init')) {
            return $this->finish($this->postWithCurl($url, $json, $timeoutSeconds, $connectTimeoutSeconds, $headers));
        }

        return $this->finish($this->postWithStreams($url, $json, $timeoutSeconds, $headers));
    }

    /**
     * @param array<string, string> $headers
     */
    private function postWithCurl(
        string $url,
        string $json,
        float $timeoutSeconds,
        float $connectTimeoutSeconds,
        array $headers,
    ): string {
        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new SsrUnavailable('SSR request failed');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($connectTimeoutSeconds * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeoutSeconds * 1000)),
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        unset($handle);

        if ($body === false) {
            throw new SsrUnavailable($error !== '' ? $error : 'SSR request failed');
        }

        if ($status >= 400 && $status < 500) {
            throw new SsrClientError('SSR HTTP status ' . $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new SsrUnavailable('SSR HTTP status ' . $status);
        }

        return $body;
    }

    /**
     * @param array<string, string> $headers
     */
    private function postWithStreams(string $url, string $json, float $timeoutSeconds, array $headers): string
    {
        $headerLines = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
        ];
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
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new SsrUnavailable('SSR request failed');
        }

        $status = 0;
        if (isset($http_response_header[0])
            && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches) === 1
        ) {
            $status = (int) $matches[1];
        }

        if ($status >= 400 && $status < 500) {
            throw new SsrClientError('SSR HTTP status ' . $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new SsrUnavailable('SSR HTTP status ' . $status);
        }

        return $body;
    }

    private function finish(string $body): SsrDocument
    {
        return SsrV1Document::fromResponse($body);
    }
}
