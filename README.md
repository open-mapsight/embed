# mapsight/embed

PHP adapter for the Mapsight **embed protocol**: assets, mount boot, sidecar
try/fallback, purge. Preset name and config are opaque caller data. Preset
chrome and page wrappers are host-owned.

```bash
composer require mapsight/embed:^0.3
```

When `ssrUrl` is set, the renderer POSTs to `{ssrUrl}/v1/render` and expects
JSON `{ v: 1, html, state, pageMeta }`. `state` is HTML-escaped onto
`data-dehydrated-state`. `pageMeta` is selected-feature or `?module=` document
meta (or `null`). Non-2xx, invalid JSON, or a missing fragment falls back to
an empty mount container (no `data-dehydrated-state`) and leaves article
title / OG defaults. Optional `requestId` / `assetVersion` on `EmbedRequest`
become `X-Request-Id` / `X-Mapsight-Asset-Version`.

Forward `requestUrl` (path + search, typically `REQUEST_URI`), `pageOrigin`
(public page origin, e.g. `https://www.example.com`), and optional
`ogImage` (absolute or root-absolute static card). The sidecar needs
`pageOrigin` when `requestUrl` is path-only so canonical / `og:url` /
default `og:image` are absolute. Apply head overrides only when `?feature=`
or `?module=` is on that request URL — use `Renderer::renderDocument()` and
`PageMetaTags`, or set the CMS title / canonical / OG / JSON-LD APIs from
`$result->pageMeta`. Do not inject head tags into the embed fragment.

Timeouts are split: `ssrConnectTimeoutSeconds` (default 0.1) and
`ssrTimeoutSeconds` (default 2.0 total — keep this ≥ `MAPSIGHT_SSR_AWAIT_TIMEOUT_MS`
when the host awaits GeoJSON). A process-local circuit breaker (5 failures /
15s cooldown) skips Node after a dead sidecar. Pass `SsrResultCache` to skip
Node on a warm `{html,state}` hit; key is `SsrCacheKey` (config + locale +
deviceClass + assetVersion + requestUrl + contract v). Share search (`?module=`,
`?feature=`) must be part of that URL so one placement’s HTML is not reused
for another.

On feature-source / pulp / GeoJSON publish, call `SsrPublish` **before** the next
page render: it POSTs sidecar `/purge` (prefer absolute list URLs; omit to
clear all) and `flush()`es the PHP fragment cache. Purging Node only still
serves stale HTML from PHP. Do not use `MAPSIGHT_FEATURE_SOURCE_REVISION` as
the bust protocol.

```php
use OpenMapsight\Embed\EmbedRequest;
use OpenMapsight\Embed\Renderer;

$result = (new Renderer())->renderDocument(new EmbedRequest(
    preset: 'infosite',
    containerId: 'mapsight-embed-1',
    config: [
        // opaque options for the preset factory the host chose
    ],
    assetBase: '/mapsight/plan',
    ssrUrl: getenv('MAPSIGHT_SSR_URL') ?: null,
    requestUrl: $_SERVER['REQUEST_URI'] ?? null,
    pageOrigin: 'https://www.example.com',
    ogImage: 'https://www.example.com/plan/img/og-default.png',
));
// CMS title / OG APIs, or:
// echo \OpenMapsight\Embed\PageMetaTags::html($result->pageMeta);
echo $result->html;

// Same request that wrote the list GeoJSON:
(new \OpenMapsight\Embed\SsrPublish(
    getenv('MAPSIGHT_SSR_URL') ?: null,
    $resultCache, // the SsrResultCache passed to Renderer, if any
))->afterFeatureSourcePublish([
    'https://www.example.com/geojson/places.geojson',
]);
```

```bash
composer install
composer test
```
