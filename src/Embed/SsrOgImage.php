<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * A rendered Open Graph card from sidecar `POST /v1/og`: bytes plus their
 * content type (`image/png`). Serve it with long-lived public caching.
 */
final class SsrOgImage
{
    public function __construct(
        public readonly string $contentType,
        public readonly string $body,
    ) {
    }
}
