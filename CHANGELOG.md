# Changelog

## Unreleased

Breaking 0.4.0 reshape. Placement data and sidecar configuration are split:
`EmbedRequest` is a placement value object; `SsrClient` is built once and
shared by `Renderer` and `SsrPublish`. `render()` returns `RenderedEmbed`
(the HTML-only method is gone). `SsrPublish::purge()` replaces
`afterFeatureSourcePublish()` and returns `PurgeResult`.

- No implicit `$_SERVER` / `getenv` / `config` key fallbacks. `config` is
  opaque; the library still writes documented v1 option keys over it.
- Share params (`feature`, `module` by default) whitelist `requestUrl` for
  the cache key, sidecar payload, and pageMeta gate.
- `psr/log` and `psr/simple-cache` are the only runtime dependencies.
  Optional `Psr16SsrResultCache`. Cache `set()` takes a TTL (default 3600 s).
- `SsrTransport::send()` returns raw HTTP. The client owns the v1 contract.
- `RenderedEmbed` exposes `ssr` / `ssrReason` / `ssrDurationMs` and fragment
  parts (`stylesheetHtml`, `preloadHtml`, `containerHtml`, `bootScriptHtml`).
- Validate `preset`, `containerId`, `requestId`, `assetVersion`. Forward
  `locale` / `deviceClass`. Optional `scriptNonce`. JS strings use
  `json_encode` + `JSON_HEX_*`.
- Cache before breaker. Breaker counts only connect / timeout / 5xx.
- v1 HTML must start with `<[A-Za-z]` and match `containerId`. JSON only,
  BOM stripped, response and state size caps. `curl_close()` removed.
- CSS `?v=`, `modulepreload`, transport protocol / `Expect` / no-follow
  hardening. `health()` and `warm()`. `Testing\FakeSsrTransport`.
- PHPStan at max, `composer validate --strict`, `--prefer-lowest` CI,
  `failOnDeprecation`. `SECURITY.md`.
- `SsrClient::ogImage(EmbedRequest)` fetches a place's Open Graph card from
  sidecar `POST /v1/og` (`@mapsight/ssr-sidecar` 0.2) with the same body as
  the render, for the host's own public OG endpoint: `SsrOgImage`
  (`contentType`, `body`) or null (no share param, breaker open, sidecar
  404 or error). `EmbedRequest::$ogImageEndpoint` forwards that endpoint as
  `options.ogImageEndpoint`, so the sidecar links
  `{endpoint}?feature=…&module=…&src=…` as `og:image` of a place; it is
  part of `SsrCacheKey`.
- Streams fallback reads the response headers from the stream metadata
  (`stream_get_meta_data()`), not `$http_response_header` (deprecated in
  PHP 8.5) or `$GLOBALS` (empty there). One code path for PHP 8.2–8.5.

## 0.3.0 — 2026-09-12

First public Packagist release. Composer name is `mapsight/embed` (MIT).
Namespace `OpenMapsight\` is unchanged. The library is the embed protocol
only: fail-open is an empty mount container. Preset chrome and page wrappers
are host-owned.

## 0.2.0 — 2026-09-02

Same public `EmbedRequest` / `Renderer` API. Host-specific legacy boot,
map-link env, and placements stay in the consuming host package.
`renderDocument()` applies sidecar `pageMeta` when `?feature=` or
`?module=` is on the request URL.
