<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Process-local gate so a dead sidecar is not called on every request.
 */
interface SsrCircuitBreaker
{
    public function allow(): bool;

    public function recordSuccess(): void;

    public function recordFailure(): void;
}
