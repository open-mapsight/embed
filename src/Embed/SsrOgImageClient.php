<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * The sidecar's Open Graph card of a placement's selected place, for the
 * host's own public OG endpoint: same `EmbedRequest` as the page render,
 * `requestUrl` carrying `?feature=` (and `module`, `src`).
 *
 * Fail-open like the renderer: null when the sidecar is unset, the request
 * has no share param, the breaker is open, the sidecar has no card (404) or
 * fails. Serve the static card then.
 */
final class SsrOgImageClient
{
    private readonly SsrCircuitBreaker $circuitBreaker;

    public function __construct(
        private readonly ?SsrOgImageTransport $transport = null,
        ?SsrCircuitBreaker $circuitBreaker = null,
    ) {
        $this->circuitBreaker = $circuitBreaker ?? new ProcessSsrCircuitBreaker();
    }

    public function fetch(EmbedRequest $request): ?SsrOgImage
    {
        if ($request->ssrUrl === null || $request->ssrUrl === '' || !$request->requestHasPageMetaParam()) {
            return null;
        }
        if (!$this->circuitBreaker->allow()) {
            return null;
        }

        try {
            $transport = $this->transport ?? new NativeSsrOgImageTransport();
            $image = $transport->postOgImage(
                rtrim($request->ssrUrl, '/') . '/v1/og',
                SsrRequestPayload::body($request),
                $request->ssrTimeoutSeconds,
                ['Accept' => 'image/png, application/json'] + SsrRequestPayload::headers($request),
                $request->ssrConnectTimeoutSeconds,
            );
            $this->circuitBreaker->recordSuccess();

            return $image;
        } catch (\Throwable) {
            $this->circuitBreaker->recordFailure();

            return null;
        }
    }
}
