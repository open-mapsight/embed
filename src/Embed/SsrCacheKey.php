<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Stable key for a v1 SSR placement: config + locale/deviceClass + assetVersion
 * + requestUrl + contract. requestUrl must be in the key so `?module=` /
 * `?feature=` (and similar search) does not reuse another URL's HTML.
 * pageOrigin / ogImage are in the key so cached pageMeta stays absolute.
 */
final class SsrCacheKey
{
    public static function for(EmbedRequest $request): string
    {
        $payload = [
            'v' => 1,
            'preset' => $request->preset,
            'containerId' => $request->containerId,
            'containerClassName' => $request->containerClassName,
            'config' => self::normalize($request->config),
            'assetVersion' => $request->assetVersion,
            'locale' => $request->locale,
            'deviceClass' => $request->deviceClass,
            'requestUrl' => $request->resolvedRequestUrl(),
            'pageOrigin' => $request->resolvedPageOrigin(),
            'ogImage' => $request->resolvedOgImage(),
        ];

        return hash(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        if (!$isList) {
            ksort($value);
        }

        return $value;
    }
}
