<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Outcome of {@see SsrPublish::afterFeatureSourcePublish()}.
 *
 * `sidecarPurged` is true only after a successful sidecar POST. A failed
 * purge leaves the PHP fragment cache alone so the next render cannot
 * refill it from a still-stale sidecar.
 */
final class SsrPurgeResult
{
    /**
     * @param list<string> $deletedKeys
     */
    public function __construct(
        public readonly bool $sidecarPurged,
        public readonly array $deletedKeys = [],
    ) {
    }
}
