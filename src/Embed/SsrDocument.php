<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Sidecar render result: container HTML plus optional selected-feature head meta.
 */
final class SsrDocument
{
    public function __construct(
        public readonly string $html,
        public readonly ?PlacePageMeta $pageMeta = null,
    ) {
    }
}
