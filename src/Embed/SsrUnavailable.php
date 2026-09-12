<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * The sidecar is unreachable, timed out, or returned 5xx.
 * Counts toward the circuit breaker.
 */
final class SsrUnavailable extends SsrException
{
}
