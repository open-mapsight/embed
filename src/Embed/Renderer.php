<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Emits an embed fragment: CSS + mount container (+ optional SSR shell) + mountEmbed boot.
 *
 * The mount container is an empty element. Preset chrome and page wrappers
 * are host-owned. Use {@see renderDocument()} when the page URL may have
 * `?feature=` or `?module=` so the host can apply {@see PlacePageMeta} to
 * the document head. {@see render()} is the HTML-only path (same fragment,
 * meta discarded).
 */
final class Renderer
{
    private readonly SsrCircuitBreaker $circuitBreaker;

    public function __construct(
        private readonly ?SsrTransport $ssrTransport = null,
        ?SsrCircuitBreaker $circuitBreaker = null,
        private readonly ?SsrResultCache $resultCache = null,
    ) {
        $this->circuitBreaker = $circuitBreaker ?? new ProcessSsrCircuitBreaker();
    }

    public function render(EmbedRequest $request): string
    {
        return $this->renderDocument($request)->html;
    }

    public function renderDocument(EmbedRequest $request): RenderedEmbed
    {
        $assetBase = rtrim($request->assetBase, '/');
        $parts = [
            sprintf(
                '<link rel="stylesheet" href="%s">',
                $this->escapeAttr($this->assetUrl($assetBase, 'mapsight.css', $request->assetVersion)),
            ),
        ];

        $containerHtml = null;
        $pageMeta = null;
        $ssrSkipped = false;

        if ($request->ssrUrl !== null && $request->ssrUrl !== '') {
            $cacheKey = SsrCacheKey::for($request);
            $cached = $this->resultCache?->get($cacheKey);
            if ($cached !== null && $cached->html !== '') {
                $containerHtml = $cached->html;
                $pageMeta = $this->pageMetaForRequest($request, $cached->pageMeta);
            } elseif (!$this->circuitBreaker->allow()) {
                $ssrSkipped = true;
            } else {
                try {
                    $transport = $this->ssrTransport ?? new NativeSsrTransport();
                    $renderUrl = rtrim($request->ssrUrl, '/') . '/v1/render';
                    $payload = [
                        'v' => 1,
                        'preset' => $request->preset,
                        'options' => $this->ssrOptions($request),
                    ];
                    if ($request->requestId !== null && $request->requestId !== '') {
                        $payload['requestId'] = $request->requestId;
                    }
                    if ($request->assetVersion !== null && $request->assetVersion !== '') {
                        $payload['assetVersion'] = $request->assetVersion;
                    }
                    $document = $transport->postJson(
                        $renderUrl,
                        $payload,
                        $request->ssrTimeoutSeconds,
                        self::ssrHeaders($request),
                        $request->ssrConnectTimeoutSeconds,
                    );
                    $this->circuitBreaker->recordSuccess();
                    $containerHtml = $document->html;
                    $pageMeta = $this->pageMetaForRequest($request, $document->pageMeta);
                    $this->resultCache?->set(
                        $cacheKey,
                        new SsrDocument($containerHtml, $pageMeta),
                    );
                } catch (SsrUnavailable) {
                    $this->circuitBreaker->recordFailure();
                    $ssrSkipped = true;
                } catch (\Throwable) {
                    $ssrSkipped = true;
                }
            }
        }

        if ($ssrSkipped) {
            $parts[] = '<!-- mapsight-ssr-skipped -->';
        }

        if ($containerHtml === null) {
            $parts[] = $this->emptyContainer($request);
        } else {
            $parts[] = trim($containerHtml);
        }

        $parts[] = $this->bootScript($request, $assetBase);

        return new RenderedEmbed(implode("\n", $parts) . "\n", $pageMeta);
    }

    /**
     * @return array<string, mixed>
     */
    private function ssrOptions(EmbedRequest $request): array
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
        if ($request->locale !== null && $request->locale !== '') {
            $options['locale'] = $request->locale;
        }
        if ($request->deviceClass !== null && $request->deviceClass !== '') {
            $options['deviceClass'] = $request->deviceClass;
        }

        return $options;
    }

    private function pageMetaForRequest(EmbedRequest $request, ?PlacePageMeta $pageMeta): ?PlacePageMeta
    {
        if (!$request->requestHasPageMetaParam()) {
            return null;
        }

        return $pageMeta;
    }

    /** @return array<string, string> */
    private static function ssrHeaders(EmbedRequest $request): array
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

    private function emptyContainer(EmbedRequest $request): string
    {
        $id = $this->escapeAttr($request->containerId);
        if ($request->containerClassName === '') {
            return sprintf('<div id="%s"></div>', $id);
        }

        return sprintf(
            '<div id="%s" class="%s"></div>',
            $id,
            $this->escapeAttr($request->containerClassName),
        );
    }

    private function bootScript(EmbedRequest $request, string $assetBase): string
    {
        $preset = $request->preset;
        $configJson = json_encode(
            $request->config,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT,
        );

        $embedUrl = $this->assetUrl($assetBase, 'embed.js', $request->assetVersion);
        $presetUrl = $this->assetUrl($assetBase, $preset . '.js', $request->assetVersion);

        return <<<HTML
<script type="module">
import {mountEmbed} from "{$this->escapeJsDoubleQuoted($embedUrl)}";
import {{$preset}} from "{$this->escapeJsDoubleQuoted($presetUrl)}";

mountEmbed("{$this->escapeJsDoubleQuoted($request->containerId)}",
	{$preset}({$configJson}),
);
</script>
HTML;
    }

    private function assetUrl(string $assetBase, string $file, ?string $assetVersion): string
    {
        $url = $assetBase . '/assets/' . $file;
        if ($assetVersion !== null && $assetVersion !== '') {
            $url .= '?v=' . rawurlencode($assetVersion);
        }

        return $url;
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeJsDoubleQuoted(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n", "\r"],
            ['\\\\', '\\"', '\\n', '\\r'],
            $value,
        );
    }
}
