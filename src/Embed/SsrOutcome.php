<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

enum SsrOutcome: string
{
    case Disabled = 'disabled';
    case Rendered = 'rendered';
    case Cached = 'cached';
    case SkippedBreaker = 'skipped_breaker';
    case SkippedError = 'skipped_error';
}
