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
     * @param list<string>|null $urls
     * @return list<string> deleted sidecar cache keys (empty when sidecar is unset or fail-open)
     */
    public function afterFeatureSourcePublish(?array $urls = null): array
    {
        $deleted = $this->purgeSidecar($urls);
        $this->resultCache?->flush();

        return $deleted;
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
     * @return list<string>
     */
    private function purgeSidecar(?array $urls): array
    {
        if ($this->ssrUrl === null || $this->ssrUrl === '') {
            return [];
        }

        $payload = [];
        if ($urls !== null && $urls !== []) {
            $payload['urls'] = array_values(array_filter($urls, static fn (mixed $url): bool => is_string($url) && $url !== ''));
        }

        try {
            $transport = $this->transport ?? new NativeSsrPurgeTransport();

            return $transport->postPurge(
                rtrim($this->ssrUrl, '/') . '/purge',
                $payload,
                $this->timeoutSeconds,
                $this->connectTimeoutSeconds,
            );
        } catch (\Throwable $error) {
            error_log('mapsight ssr purge failed: ' . $error->getMessage());

            return [];
        }
    }
}
