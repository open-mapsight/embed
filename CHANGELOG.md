# Changelog

## 0.3.3 — 2026-11-03

Read the guaranteed stream timeout metadata flag directly, keeping the OG
transport compatible with strict static analysis. Runtime behavior is unchanged.

## 0.3.2 — 2026-11-03

Open Graph cards from the sidecar (`POST /v1/og`, `@mapsight/ssr-sidecar`
0.2) for the host's own public OG endpoint. No change for hosts that do not
use it.

- `SsrOgImageClient::fetch(EmbedRequest)` posts the placement's render
  payload to `{ssrUrl}/v1/og` and returns `SsrOgImage` (`contentType`,
  `body`), or null when `ssrUrl` is unset, the request URL has no
  `?feature=` / `?module=`, the breaker is open, or the sidecar has no card
  (404) or fails. Serve the static card then. Transport:
  `SsrOgImageTransport` / `NativeSsrOgImageTransport` (cURL, else streams).
- `EmbedRequest::$ogImageEndpoint` (explicit field, else
  `config.ogImageEndpoint`, else env `MAPSIGHT_OG_IMAGE_ENDPOINT`): the
  absolute URL of that endpoint, forwarded as `options.ogImageEndpoint`. The
  sidecar then links `{endpoint}?feature=…&module=…&src=…` as the
  `og:image` of a place. Part of `SsrCacheKey`.
- Internal `SsrRequestPayload` builds the v1 body and headers for both the
  renderer and the card client, so they resolve the same place.
- The card transport rejects redirects, non-image or empty responses and
  images larger than 4 MiB. Requests retain the 256 KiB body cap.

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
