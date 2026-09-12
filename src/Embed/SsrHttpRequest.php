<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Raw HTTP call issued by {@see SsrClient}.
 *
 * @param array<string, string> $headers
 */
final class SsrHttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly float $timeoutSeconds = 2.0,
        public readonly float $connectTimeoutSeconds = 0.1,
    ) {
    }
}
