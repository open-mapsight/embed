<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Full embed fragment (CSS + container + boot) plus optional document meta.
 *
 * Apply {@see $pageMeta} with the CMS title / OG APIs (or {@see PageMetaTags}),
 * then inject {@see $html} into the mid-page slot. Do not put head tags in $html.
 */
final class RenderedEmbed
{
    public function __construct(
        public readonly string $html,
        public readonly ?PlacePageMeta $pageMeta = null,
    ) {
    }
}
