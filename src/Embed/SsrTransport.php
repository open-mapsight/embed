<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

interface SsrTransport
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     *
     * @throws \Throwable when the sidecar is unreachable or returns a non-success body
     */
    public function postJson(
        string $url,
        array $payload,
        float $timeoutSeconds,
        array $headers = [],
        float $connectTimeoutSeconds = 0.1,
    ): SsrDocument;
}
