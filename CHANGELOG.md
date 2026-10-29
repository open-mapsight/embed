# Changelog

## 0.3.1 — 2026-10-29

PHP 8.5 deprecation fixes for the 0.3 line. No API change.

- `NativeSsrTransport` and `NativeSsrPurgeTransport` no longer call
  `curl_close()` (deprecated in PHP 8.5, no effect since 8.0). It was logged
  on every cURL render and purge.
- The streams fallback reads the response headers from
  `stream_get_meta_data()`, not `$http_response_header` (deprecated in
  PHP 8.5).
- Tests cover both transports over cURL and streams and fail on
  deprecations.

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
