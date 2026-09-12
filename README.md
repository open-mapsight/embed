# mapsight/embed

PHP adapter for the Mapsight **embed protocol**. It emits a fragment you splice
into a page you already own: stylesheet, mount container, optional SSR try,
`mountEmbed` boot.

Preset name and config are opaque. **You** own wrappers, first-paint chrome,
and `<head>`. This package does not.

```bash
composer require mapsight/embed:^0.3
```

Requires PHP 8.2+. MIT.

---

## Who owns what

```
┌──────────────────────── host page (your CMS) ─────────────────────────┐
│  layout, wrappers, <head>                                              │
│                                                                        │
│    ┌────────────── this library ──────────────┐                        │
│    │  <link mapsight.css>                     │                        │
│    │  <div id="…">          ← empty on miss   │                        │
│    │  <script type=module>  ← mountEmbed      │                        │
│    │         │                                │                        │
│    │         │  POST /v1/render               │     ┌───────────────┐  │
│    │         └───────────────────────────────►│────►│  SSR sidecar  │  │
│    │                    JSON { html, state,   │     │  (optional,   │  │
│    │                           pageMeta }     │     │   private)    │  │
│    └──────────────────────────────────────────┘     └───────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
                     browser: preset.js + mountEmbed
                     reads data-dehydrated-state if present
```

| Piece | Owner |
| --- | --- |
| Page shell, wrappers, preset chrome | **Host** |
| Preset string + embed config | **Host** (forwarded as-is) |
| Assets, mount container, boot script | **This library** |
| Sidecar try / timeout / circuit breaker / fail-open | **This library** |
| React render + dehydrated GIS state | **Sidecar** (`ghcr.io/open-mapsight/ssr-sidecar`) + your `render.js` |
| Hydrate in the browser | **`@mapsight/ui`** `mountEmbed` |

SSR is acceleration, not a hard dependency. If the sidecar is down, slow, or
returns junk, the page still boots client-only.

```
  request
     │
     ├─ no ssrUrl ──────────────────────────► empty <div id> + boot
     │
     ├─ POST /v1/render
     │       │
     │       ├─ 2xx + html ─► sidecar fragment (data-dehydrated-state)
     │       │
     │       └─ miss / timeout / 5xx ─► <!-- mapsight-ssr-skipped -->
     │                                  empty <div id> + boot
     └─ browser always runs mountEmbed(preset(config))
```

---

## Usage

```php
use OpenMapsight\Embed\EmbedRequest;
use OpenMapsight\Embed\Renderer;

$result = (new Renderer())->renderDocument(new EmbedRequest(
    preset: 'simpleMap',
    containerId: 'mapsight-embed-1',
    config: [
        // opaque options for the preset factory your host build exports
    ],
    assetBase: '/mapsight/plan',
    ssrUrl: getenv('MAPSIGHT_SSR_URL') ?: null,
    requestUrl: $_SERVER['REQUEST_URI'] ?? null,
    pageOrigin: 'https://www.example.com',
    ogImage: 'https://www.example.com/plan/img/og-default.png',
));

// Host <head> APIs, or:
// echo \OpenMapsight\Embed\PageMetaTags::html($result->pageMeta);
echo $result->html;
```

`$result->html` is a **fragment**, not a document. Wrap it however you like.
`$result->pageMeta` is set only when the request URL has `?feature=` or
`?module=` *and* the sidecar returned meta. Apply it with your CMS title /
canonical / OG / JSON-LD APIs. Do not inject head tags into the fragment.

`preset` becomes `/assets/{preset}.js` next to `embed.js` under `assetBase`.
`containerClassName` is optional; if you pass it, the empty mount and the
sidecar request both get that class.

Pass `requestUrl` (path + search, typically `REQUEST_URI`) and, when that URL
is path-only, `pageOrigin` so the sidecar can make absolute canonical / `og:url`
/ default `og:image`. Share search (`?feature=`, `?module=`) must be on that
URL so one placement’s HTML is not reused for another.

---

## Sidecar

This library POSTs to `{ssrUrl}/v1/render` and expects JSON
`{ v: 1, html, state, pageMeta }`. `state` is HTML-escaped onto
`data-dehydrated-state`. Optional `requestId` / `assetVersion` become
`X-Request-Id` / `X-Mapsight-Asset-Version`.

The process is generic and stays **off public ingress**. Hosts pull
[`ghcr.io/open-mapsight/ssr-sidecar`](https://github.com/open-mapsight/mapsight/tree/main/packages/ssr-sidecar)
and bind-mount their own `render.js`. The image does not contain a host bundle.

| Method | Path | Role |
| --- | --- | --- |
| `GET` | `/health` | Liveness |
| `POST` | `/v1/render` | One placement → `{ html, state, pageMeta }` |
| `POST` | `/purge` | Drop sidecar caches (see publish below) |

There is no `POST /render`.

Timeouts are split: `ssrConnectTimeoutSeconds` (default 0.1) and
`ssrTimeoutSeconds` (default 2.0 total). Keep the total ≥ the sidecar’s
`MAPSIGHT_SSR_AWAIT_TIMEOUT_MS` when your module awaits GeoJSON. After 5
failures a process-local breaker skips Node for 15s.

Pass an `SsrResultCache` (e.g. `ArraySsrResultCache`, or your Redis adapter)
to skip Node on a warm `{html,state}` hit. The key is `SsrCacheKey`: config +
locale + deviceClass + assetVersion + requestUrl + contract `v`.

Wire and hydration details live in the Mapsight monorepo — do not fork them
here:

- [SSR and state hydration](https://github.com/open-mapsight/mapsight/blob/main/docs/integration/SSR_HYDRATION.md) — `data-dehydrated-state`, fail-open, size bounds
- [`@mapsight/ssr-sidecar`](https://github.com/open-mapsight/mapsight/blob/main/packages/ssr-sidecar/README.md) — image, env, `/v1/render` / `/purge`
- [CMS PHP embed](https://github.com/open-mapsight/mapsight/blob/main/docs/integration/CMS_PHP.md) — snippet pattern this library automates
- [Decision 006](https://github.com/open-mapsight/mapsight/blob/main/docs/architecture/decisions/006-ssr-state-hydration-goal.md) — why PHP → Node sidecar
- [Privacy: SSR sidecar](https://github.com/open-mapsight/mapsight/blob/main/docs/integration/PRIVACY_DATA_FLOWS.md#ssr-sidecar-optional) — keep the POST inside your network

---

## Publish / purge

When a feature-source or GeoJSON file changes, call `SsrPublish` **before** the
next page render. It POSTs sidecar `/purge` (prefer absolute list URLs; omit to
clear all) and `flush()`es the PHP fragment cache. Purging Node only still
serves stale HTML from PHP.

```php
(new \OpenMapsight\Embed\SsrPublish(
    getenv('MAPSIGHT_SSR_URL') ?: null,
    $resultCache, // the SsrResultCache passed to Renderer, if any
))->afterFeatureSourcePublish([
    'https://www.example.com/geojson/places.geojson',
]);
```

Do not use a feature-source revision env var as the bust protocol.

---

## Develop

```bash
composer install
composer test
```
