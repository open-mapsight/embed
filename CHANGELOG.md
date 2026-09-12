# Changelog

## Unreleased

- Validate `preset` as a JS identifier and `requestId` / `assetVersion` as header-safe tokens.
- Forward `locale` and `deviceClass` to the sidecar in `options`.
- Look up the SSR result cache before consulting the circuit breaker.
- Trip the breaker only on `SsrUnavailable` (connect / timeout / 5xx), not client or parse errors.
- Reject SSR HTML that does not start with a real element (no comment injection).
- Accept only v1 JSON from the sidecar; strip a UTF-8 BOM. Remove deprecated `curl_close()`.
- Cache-bust `mapsight.css` with `?v=` like the module imports.
- `SsrPublish::afterFeatureSourcePublish()` returns `SsrPurgeResult`. Blank URL lists are an error, not a purge-all. A failed sidecar purge no longer flushes the PHP cache.

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
