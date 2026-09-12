<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * A single placement cannot be rendered (bad request, 4xx, v1 parse error).
 * Does not trip the circuit breaker.
 */
final class SsrClientError extends SsrException
{
}
