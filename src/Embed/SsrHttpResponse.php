<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Raw HTTP result. The client owns status classification and v1 parsing.
 */
final class SsrHttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $contentType,
        public readonly string $body,
    ) {
    }
}
