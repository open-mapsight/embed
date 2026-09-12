<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * POST {ssrUrl}/purge → deleted cache keys (JSON string[]).
 */
final class NativeSsrPurgeTransport implements SsrPurgeTransport
{
    public function postPurge(
        string $url,
        array $payload,
        float $timeoutSeconds,
        float $connectTimeoutSeconds = 0.1,
    ): array {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (function_exists('curl_init')) {
            return $this->decode($this->postWithCurl($url, $json, $timeoutSeconds, $connectTimeoutSeconds));
        }

        return $this->decode($this->postWithStreams($url, $json, $timeoutSeconds));
    }

    private function postWithCurl(
        string $url,
        string $json,
        float $timeoutSeconds,
        float $connectTimeoutSeconds,
    ): string {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('SSR purge request failed');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($connectTimeoutSeconds * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeoutSeconds * 1000)),
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new \RuntimeException($error !== '' ? $error : 'SSR purge request failed');
        }

        if ($status === 204) {
            return '[]';
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('SSR purge HTTP status ' . $status);
        }

        return $body;
    }

    private function postWithStreams(string $url, string $json, float $timeoutSeconds): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\nContent-Length: " . strlen($json) . "\r\n",
                'content' => $json,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('SSR purge request failed');
        }

        $status = 0;
        if (isset($http_response_header[0])
            && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches) === 1
        ) {
            $status = (int) $matches[1];
        }

        if ($status === 204) {
            return '[]';
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('SSR purge HTTP status ' . $status);
        }

        return $body;
    }

    /** @return list<string> */
    private function decode(string $body): array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return [];
        }

        try {
            $data = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('SSR purge response is not JSON', 0, $e);
        }

        if (!is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException('SSR purge response must be a JSON array');
        }

        $keys = [];
        foreach ($data as $item) {
            if (!is_string($item)) {
                throw new \RuntimeException('SSR purge response must be a JSON string array');
            }
            $keys[] = $item;
        }

        return $keys;
    }
}
