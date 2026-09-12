<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

use Psr\SimpleCache\CacheInterface;

/**
 * {@see SsrResultCache} over any PSR-16 implementation (Redis, APCu, filesystem).
 */
final class Psr16SsrResultCache implements SsrResultCache
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function get(string $key): ?SsrDocument
    {
        $value = $this->cache->get($key);

        return $value instanceof SsrDocument ? $value : null;
    }

    public function set(string $key, SsrDocument $document, int $ttl): void
    {
        $this->cache->set($key, $document, $ttl);
    }

    public function flush(): void
    {
        $this->cache->clear();
    }
}
