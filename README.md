# mapsight/embed

PHP adapter for the Mapsight **embed protocol**. It emits a fragment you splice
into a page you already own: stylesheet, modulepreload, mount container,
optional SSR try, `mountEmbed` boot.

Composer package: `mapsight/embed`. Source: `open-mapsight/embed` (GitHub org).
Those names differ on purpose — Packagist vendor vs GitHub org.

Preset name and config are opaque. **You** own wrappers, first-paint chrome,
and `<head>`. This package does not.

```bash
composer require mapsight/embed:^0.4
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
│    │  <link rel=modulepreload>                │                        │
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
     ├─ no SsrClient ───────────────────────► empty <div id> + boot
     │
     ├─ cache hit ──────────────────────────► cached fragment
     │
     ├─ breaker open ───────────────────────► <!-- mapsight-ssr-skipped -->
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

Build `SsrClient` once (DI / container). `EmbedRequest` is one placement.

```php
use OpenMapsight\Embed\EmbedRequest;
use OpenMapsight\Embed\Psr16SsrResultCache;
use OpenMapsight\Embed\Renderer;
use OpenMapsight\Embed\SsrClient;
use OpenMapsight\Embed\SsrPublish;

$ssr = new SsrClient(
    ssrUrl: getenv('MAPSIGHT_SSR_URL') ?: 'http://127.0.0.1:4123',
    resultCache: new Psr16SsrResultCache($psr16), // Redis / APCu / filesystem
    logger: $logger, // optional PSR-3
);
$renderer = new Renderer($ssr); // omit $ssr for client-only

$result = $renderer->render(new EmbedRequest(
    preset: 'simpleMap',
    containerId: 'mapsight-embed-1',
    config: [
        // opaque options for the preset factory your host build exports
    ],
    assetBase: '/mapsight/plan',
    requestUrl: $request->getRequestUri(), // host-owned, explicit
    pageOrigin: 'https://www.example.com',
    ogImage: 'https://www.example.com/plan/img/og-default.png',
    requestId: $request->headers->get('X-Request-Id'),
    locale: 'de',
    deviceClass: 'desktop',
));

// Host <head> APIs, or:
// echo $result->stylesheetHtml;
// echo $result->preloadHtml;
// echo \OpenMapsight\Embed\PageMetaTags::html($result->pageMeta);
echo $result->html;
```

`$result->html` is a **fragment**, not a document. The same content is also
split into `stylesheetHtml`, `preloadHtml`, `containerHtml`, and
`bootScriptHtml` so a CMS head-injection API can take the links.
`$result->pageMeta` is set only when the normalised request URL has a share
parameter (`?feature=` / `?module=` by default) *and* the sidecar returned
meta. `$result->ssr` is `disabled` / `rendered` / `cached` /
`skipped_breaker` / `skipped_error`.

`preset` becomes `/assets/{preset}.js` next to `embed.js` under `assetBase`
and is interpolated as a JS import binding, so it must be a JavaScript
identifier (not `my-map`, not a reserved word). `containerId` must match
`^[A-Za-z][A-Za-z0-9_:.-]*$` and stay stable across renders or the cache
will miss. `containerClassName` is optional. `locale` and `deviceClass` are
forwarded in sidecar `options` when set. `assetVersion` cache-busts CSS and
both modules. `scriptNonce` is emitted on the inline module script.

Pass `requestUrl` (path + search) and, when that URL is path-only,
`pageOrigin` so the sidecar can make absolute canonical / `og:url` /
default `og:image`. The client keeps only configured share parameters on
that URL (default `feature`, `module`) before it reaches the cache key, the
sidecar, or the pageMeta gate.

### Server-Timing

```php
header(sprintf(
    'Server-Timing: mapsight-ssr;dur=%.1f;desc=%s',
    $result->ssrDurationMs,
    $result->ssr->value,
));
```

Suggested series if you already scrape metrics:
`mapsight_ssr_requests_total{outcome}`, `mapsight_ssr_duration_seconds`,
`mapsight_ssr_cache_hits_total`, `mapsight_ssr_breaker_open`.

---

## Trust boundaries

| Input | Trust | Notes |
| --- | --- | --- |
| Host fields on `EmbedRequest` / `SsrClient` | Trusted | You constructed them. |
| Sidecar JSON | Private network, mostly trusted | Parsed; HTML must start with a real element whose `id` matches `containerId`. Size-capped. |
| `requestUrl` | Untrusted | Normalised to path + share-param whitelist, capped at 2048 bytes. Never read from `$_SERVER`. |

`requestId` and `assetVersion` must match `[A-Za-z0-9._-]{1,200}` because they
become HTTP headers. Validate or regenerate inbound `X-Request-Id` in the host
before passing it in.

---

## Sidecar

This library POSTs to `{ssrUrl}/v1/render` and expects JSON
`{ v: 1, html, state, pageMeta }` with `Content-Type: application/json`.
`state` is HTML-escaped onto `data-dehydrated-state`. Optional `requestId` /
`assetVersion` become `X-Request-Id` / `X-Mapsight-Asset-Version`.

### v1 request

| Field | Role |
| --- | --- |
| `v` | Contract version (`1`) |
| `preset` | JS identifier / `/assets/{preset}.js` |
| `requestId` / `assetVersion` | Optional, also sent as headers |
| `options.containerId` | Required by the sidecar |
| `options.containerClassName` | Optional |
| `options.requestUrl` | Normalised path + share params |
| `options.pageOrigin` / `options.ogImage` | Absolute / root-absolute |
| `options.locale` / `options.deviceClass` | Optional, forwarded when set |
| `options.*` | Host `config` keys, overwritten by the documented keys above |

The process is generic and stays **off public ingress**. Hosts pull
[`ghcr.io/open-mapsight/ssr-sidecar`](https://github.com/open-mapsight/mapsight/tree/main/packages/ssr-sidecar)
and bind-mount their own `render.js`. The image does not contain a host bundle.

| Method | Path | Role |
| --- | --- | --- |
| `GET` | `/health` | Liveness (`SsrClient::health()`) |
| `POST` | `/v1/render` | One placement → `{ html, state, pageMeta }` |
| `POST` | `/purge` | Drop sidecar caches |

There is no `POST /render`.

Timeouts are split: `connectTimeoutSeconds` (default 0.1) and
`timeoutSeconds` (default 2.0 total). Keep the total ≥ the sidecar’s
`MAPSIGHT_SSR_AWAIT_TIMEOUT_MS` when your module awaits GeoJSON. After 5
connect / timeout / 5xx failures a process-local breaker skips Node for 15s.
4xx, encode errors, size caps, and v1 parse errors are logged and fail open
without opening the breaker.

Pass an `SsrResultCache` (e.g. `Psr16SsrResultCache`, or `ArraySsrResultCache`
in tests — 256-entry LRU) to skip Node on a warm `{html,state}` hit. The
cache is consulted before the circuit breaker. Entries expire (`ttl`, default
3600 s). The key is `SsrCacheKey`: config + locale + deviceClass +
assetVersion + normalised requestUrl + contract `v`.

`SsrClient::warm($request)` forces a sidecar call and stores the result
(publish then warm).

Wire and hydration details live in the Mapsight monorepo — do not fork them
here:

- [SSR and state hydration](https://github.com/open-mapsight/mapsight/blob/main/docs/integration/SSR_HYDRATION.md)
- [`@mapsight/ssr-sidecar`](https://github.com/open-mapsight/mapsight/blob/main/packages/ssr-sidecar/README.md)
- [CMS PHP embed](https://github.com/open-mapsight/mapsight/blob/main/docs/integration/CMS_PHP.md)
- [Decision 006](https://github.com/open-mapsight/mapsight/blob/main/docs/architecture/decisions/006-ssr-state-hydration-goal.md)
- [Privacy: SSR sidecar](https://github.com/open-mapsight/mapsight/blob/main/docs/integration/PRIVACY_DATA_FLOWS.md#ssr-sidecar-optional)

---

## Publish / purge

When a feature-source or GeoJSON file changes, call `SsrPublish` **before**
the next page render. It POSTs sidecar `/purge` (prefer absolute list URLs;
omit or pass `[]` to clear all) and `flush()`es the PHP fragment cache **only
after a successful sidecar purge**. A list that filters down to no URLs
(e.g. `['']`) throws instead of purging everything.

```php
$result = (new SsrPublish($ssr))->purge([
    'https://www.example.com/geojson/places.geojson',
]);
if (!$result->sidecarPurged) {
    // POST failed — PHP cache was not flushed
}
```

Do not use a feature-source revision env var as the bust protocol.

---

## Develop

```bash
composer install
composer test
composer phpstan
composer validate --strict
```
