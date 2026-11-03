<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

interface SsrOgImageTransport
{
    /**
     * POST the render payload to `{ssrUrl}/v1/og`.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     *
     * @return SsrOgImage|null null when the sidecar has no card (HTTP 404)
     *
     * @throws \Throwable when the sidecar is unreachable or answers with anything else
     */
    public function postOgImage(
        string $url,
        array $payload,
        float $timeoutSeconds,
        array $headers = [],
        float $connectTimeoutSeconds = 0.1,
    ): ?SsrOgImage;
}
