<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Raw HTTP to the sidecar. One interface for render, purge, and health.
 *
 * @throws SsrUnavailable on connect errors, timeouts, and other transport failures
 */
interface SsrTransport
{
    public function send(SsrHttpRequest $request): SsrHttpResponse;
}
