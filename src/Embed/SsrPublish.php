<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Publish hook: drop sidecar documents, then flush PHP fragments.
 */
final class SsrPublish
{
    public function __construct(private readonly SsrClient $ssr)
    {
    }

    /**
     * Call before the next page render. Prefer absolute GeoJSON URLs.
     * Omit $urls or pass [] to clear the whole sidecar cache.
     *
     * A list that filters down to no URLs (e.g. `['']`) is an error, not a
     * purge-all. A failed sidecar POST does not flush the PHP cache.
     *
     * @param list<string> $urls
     */
    public function purge(array $urls = []): PurgeResult
    {
        return $this->ssr->purge($urls);
    }
}
