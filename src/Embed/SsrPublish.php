<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * CMS / pulp publish hook: drop sidecar xhr-json documents, then flush PHP fragments.
 */
final class SsrPublish
{
    public function __construct(
        private readonly ?string $ssrUrl = null,
        private readonly ?SsrResultCache $resultCache = null,
        private readonly ?SsrPurgeTransport $transport = null,
        private readonly float $timeoutSeconds = 2.0,
        private readonly float $connectTimeoutSeconds = 0.1,
    ) {}

    /**
     * Call before the next page render. Prefer absolute GeoJSON URLs.
     * Omit $urls or pass [] to clear the whole sidecar cache.
     *
     * A list that filters down to no URLs (e.g. `['']`) is an error, not a
     * purge-all. A failed sidecar POST does not flush the PHP cache.
     *
     * @param list<string>|null $urls
     */
    public function afterFeatureSourcePublish(?array $urls = null): SsrPurgeResult
    {
        if ($this->ssrUrl === null || $this->ssrUrl === '') {
            $this->resultCache?->flush();

            return new SsrPurgeResult(false, []);
        }

        $payload = $this->purgePayload($urls);

        try {
            $transport = $this->transport ?? new NativeSsrPurgeTransport();
            $deleted = $transport->postPurge(
                rtrim($this->ssrUrl, '/') . '/purge',
                $payload,
                $this->timeoutSeconds,
                $this->connectTimeoutSeconds,
            );
        } catch (\Throwable $error) {
            error_log('mapsight ssr purge failed: ' . $error->getMessage());

            return new SsrPurgeResult(false, []);
        }

        $this->resultCache?->flush();

        return new SsrPurgeResult(true, $deleted);
    }

    public static function fromEnv(?SsrResultCache $resultCache = null): self
    {
        $ssrUrl = getenv('MAPSIGHT_SSR_URL');
        if (!is_string($ssrUrl) || $ssrUrl === '') {
            $ssrUrl = null;
        }

        return new self($ssrUrl, $resultCache);
    }

    /**
     * @param list<string>|null $urls
     * @return array<string, mixed>
     */
    private function purgePayload(?array $urls): array
    {
        if ($urls === null || $urls === []) {
            return [];
        }

        $filtered = array_values(array_filter(
            $urls,
            static fn (mixed $url): bool => is_string($url) && $url !== '',
        ));
        if ($filtered === []) {
            throw new \InvalidArgumentException(
                'afterFeatureSourcePublish received only empty urls; refusing to purge the whole sidecar cache',
            );
        }

        return ['urls' => $filtered];
    }
}
