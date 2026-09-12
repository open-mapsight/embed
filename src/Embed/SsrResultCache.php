<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Optional PHP-side store of a successful SSR document.
 * Must keep html + pageMeta together so a cache hit can still set the head.
 */
interface SsrResultCache
{
    public function get(string $key): ?SsrDocument;

    public function set(string $key, SsrDocument $document, int $ttl): void;

    /** Drop every stored fragment so the next render cannot reuse pre-publish HTML. */
    public function flush(): void;
}
