<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

interface SsrPurgeTransport
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     *
     * @throws \Throwable when the sidecar is unreachable or returns a non-success body
     */
    public function postPurge(
        string $url,
        array $payload,
        float $timeoutSeconds,
        float $connectTimeoutSeconds = 0.1,
    ): array;
}
