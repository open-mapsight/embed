<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Open Graph fields on {@see PlacePageMeta}. `url` is the share permalink.
 */
final class PlacePageMetaOg
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $url,
        public readonly string $type,
        public readonly string $image,
    ) {
    }
}
