<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Internal resolve() result: document plus why SSR was used or skipped.
 */
final class SsrRenderResult
{
    public function __construct(
        public readonly SsrOutcome $outcome,
        public readonly ?SsrDocument $document = null,
        public readonly ?string $reason = null,
        public readonly float $durationMs = 0.0,
    ) {
    }
}
