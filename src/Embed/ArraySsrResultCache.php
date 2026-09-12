<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * In-process cache for tests and single-worker smoke. Not shared across FPM workers.
 */
final class ArraySsrResultCache implements SsrResultCache
{
    /** @var array<string, SsrDocument> */
    private array $items = [];

    public function get(string $key): ?SsrDocument
    {
        return $this->items[$key] ?? null;
    }

    public function set(string $key, SsrDocument $document): void
    {
        $this->items[$key] = $document;
    }

    public function flush(): void
    {
        $this->items = [];
    }
}
