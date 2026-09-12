<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Embed fragment parts plus optional document meta and SSR outcome.
 *
 * Apply {@see $pageMeta} with the CMS title / OG APIs (or {@see PageMetaTags}),
 * put {@see $stylesheetHtml} / {@see $preloadHtml} in `<head>` when you can,
 * then inject {@see $html} (or the remaining parts) into the page slot.
 */
final class RenderedEmbed
{
    public function __construct(
        public readonly string $html,
        public readonly ?PlacePageMeta $pageMeta = null,
        public readonly SsrOutcome $ssr = SsrOutcome::Disabled,
        public readonly ?string $ssrReason = null,
        public readonly float $ssrDurationMs = 0.0,
        public readonly string $stylesheetHtml = '',
        public readonly string $preloadHtml = '',
        public readonly string $containerHtml = '',
        public readonly string $bootScriptHtml = '',
    ) {
    }
}
