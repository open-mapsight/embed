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
- Streams fallback reads `$http_response_header` in the `file_get_contents`
  caller (PHP < 8.4). That identifier lives in a class loaded only then, so
  PHP 8.5 does not compile the deprecation. `$GLOBALS` is empty there.

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
