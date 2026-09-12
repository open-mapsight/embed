<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * In-process LRU cache (256 entries) for tests and single-worker smoke.
 * Not shared across FPM workers. Expired entries are dropped on get/set.
 */
final class ArraySsrResultCache implements SsrResultCache
{
    public const MAX_ENTRIES = 256;

    /**
     * @var array<string, array{document: SsrDocument, expiresAt: float}>
     */
    private array $items = [];

    public function get(string $key): ?SsrDocument
    {
        $item = $this->items[$key] ?? null;
        if ($item === null) {
            return null;
        }
        if ($item['expiresAt'] <= microtime(true)) {
            unset($this->items[$key]);

            return null;
        }

        unset($this->items[$key]);
        $this->items[$key] = $item;

        return $item['document'];
    }

    public function set(string $key, SsrDocument $document, int $ttl): void
    {
        unset($this->items[$key]);
        $this->items[$key] = [
            'document' => $document,
            'expiresAt' => microtime(true) + $ttl,
        ];
        while (count($this->items) > self::MAX_ENTRIES) {
            array_shift($this->items);
        }
    }

    public function flush(): void
    {
        $this->items = [];
    }
}
