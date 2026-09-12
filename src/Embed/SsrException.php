<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Base for SSR failures. Subclasses distinguish client mistakes from sidecar outages.
 */
class SsrException extends \RuntimeException
{
}
