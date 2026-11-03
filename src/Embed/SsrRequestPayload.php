<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * The v1 request a placement sends to the sidecar, shared by the renderer
 * (`/v1/render`) and the card client (`/v1/og`) so both resolve the same
 * place.
 *
 * @internal
 */
final class SsrRequestPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function body(EmbedRequest $request): array
    {
        $payload = [
            'v' => 1,
            'preset' => $request->preset,
            'options' => self::options($request),
        ];
        if ($request->requestId !== null && $request->requestId !== '') {
            $payload['requestId'] = $request->requestId;
        }
        if ($request->assetVersion !== null && $request->assetVersion !== '') {
            $payload['assetVersion'] = $request->assetVersion;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function options(EmbedRequest $request): array
    {
        $options = array_merge($request->config, [
            'containerId' => $request->containerId,
        ]);
        if ($request->containerClassName !== '') {
            $options['containerClassName'] = $request->containerClassName;
        }
        $requestUrl = $request->resolvedRequestUrl();
        if ($requestUrl !== null) {
            $options['requestUrl'] = $requestUrl;
        }
        $pageOrigin = $request->resolvedPageOrigin();
        if ($pageOrigin !== null) {
            $options['pageOrigin'] = $pageOrigin;
        }
        $ogImage = $request->resolvedOgImage();
        if ($ogImage !== null) {
            $options['ogImage'] = $ogImage;
        }
        $ogImageEndpoint = $request->resolvedOgImageEndpoint();
        if ($ogImageEndpoint !== null) {
            $options['ogImageEndpoint'] = $ogImageEndpoint;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function headers(EmbedRequest $request): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($request->requestId !== null && $request->requestId !== '') {
            $headers['X-Request-Id'] = $request->requestId;
        }
        if ($request->assetVersion !== null && $request->assetVersion !== '') {
            $headers['X-Mapsight-Asset-Version'] = $request->assetVersion;
        }

        return $headers;
    }
}
