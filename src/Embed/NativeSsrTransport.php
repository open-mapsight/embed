<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Loopback/compose HTTP via cURL when available, else PHP streams.
 *
 * Streams `timeout` is per read, not a total budget, so a slow-dripping
 * sidecar can exceed {@see SsrHttpRequest::$timeoutSeconds}. Streams also
 * ignore `connectTimeoutSeconds` (needs ext-curl).
 */
final class NativeSsrTransport implements SsrTransport
{
    public function send(SsrHttpRequest $request): SsrHttpResponse
    {
        if (function_exists('curl_init')) {
            return $this->withCurl($request);
        }

        return $this->withStreams($request);
    }

    private function withCurl(SsrHttpRequest $request): SsrHttpResponse
    {
        $handle = curl_init($request->url);
        if ($handle === false) {
            throw new SsrUnavailable('SSR request failed');
        }

        $method = $request->method;
        if ($method === '') {
            throw new SsrUnavailable('SSR request failed');
        }

        $headerLines = $this->headerLines($request);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($request->connectTimeoutSeconds * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) round($request->timeoutSeconds * 1000)),
        ];
        if ($request->body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $request->body;
        }

        curl_setopt_array($handle, $opts);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $error = curl_error($handle);
        unset($handle);

        if (!is_string($body)) {
            throw new SsrUnavailable($error !== '' ? $error : 'SSR request failed');
        }

        return new SsrHttpResponse(
            $status,
            is_string($contentType) && $contentType !== '' ? $contentType : null,
            $body,
        );
    }

    private function withStreams(SsrHttpRequest $request): SsrHttpResponse
    {
        $headerLines = $this->headerLines($request);
        if ($request->body !== null) {
            $headerLines[] = 'Content-Length: ' . strlen($request->body);
        }

        $http = [
            'method' => $request->method,
            'header' => implode("\r\n", $headerLines) . "\r\n",
            'timeout' => $request->timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
        ];
        if ($request->body !== null) {
            $http['content'] = $request->body;
        }

        // PHP 8.5 deprecates the $http_response_header identifier itself.
        // Keep that name out of this file; PHP < 8.4 loads a sidecar class.
        if (function_exists('http_get_last_response_headers')) {
            $body = @file_get_contents($request->url, false, stream_context_create(['http' => $http]));
            if ($body === false) {
                throw new SsrUnavailable('SSR request failed');
            }

            return $this->responseFromHeaderLines(
                $this->stringLines(http_get_last_response_headers()),
                $body,
            );
        }

        [$body, $headers] = LegacyHttpStreamFetch::get($request->url, $http);

        return $this->responseFromHeaderLines($this->stringLines($headers), $body);
    }

    /**
     * @return list<string>
     */
    private function headerLines(SsrHttpRequest $request): array
    {
        $lines = ['Expect:'];
        $headers = $request->headers;
        if ($request->body !== null && !isset($headers['Content-Type']) && !isset($headers['content-type'])) {
            $headers['Content-Type'] = 'application/json';
        }
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    /**
     * @param list<string> $headers
     */
    private function responseFromHeaderLines(array $headers, string $body): SsrHttpResponse
    {
        $status = 0;
        $contentType = null;
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches) === 1) {
            $status = (int) $matches[1];
        }
        foreach ($headers as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                $contentType = trim(substr($line, strlen('Content-Type:')));
                break;
            }
        }

        return new SsrHttpResponse($status, $contentType, $body);
    }

    /**
     * @return list<string>
     */
    private function stringLines(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $headers = [];
        foreach ($raw as $line) {
            if (is_string($line)) {
                $headers[] = $line;
            }
        }

        return $headers;
    }
}
