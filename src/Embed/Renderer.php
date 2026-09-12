<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * Emits an embed fragment: CSS + modulepreload + mount container + mountEmbed boot.
 *
 * The mount container is an empty element on a miss. Preset chrome and page
 * wrappers are host-owned. {@see render()} is the only entry point.
 */
final class Renderer
{
    public function __construct(private readonly ?SsrClient $ssr = null)
    {
    }

    public function render(EmbedRequest $request): RenderedEmbed
    {
        $assetBase = rtrim($request->assetBase, '/');
        $stylesheet = sprintf(
            '<link rel="stylesheet" href="%s">',
            $this->escapeAttr($this->assetUrl($assetBase, 'mapsight.css', $request->assetVersion)),
        );
        $embedUrl = $this->assetUrl($assetBase, 'embed.js', $request->assetVersion);
        $presetUrl = $this->assetUrl($assetBase, $request->preset . '.js', $request->assetVersion);
        $preload = sprintf(
            '<link rel="modulepreload" href="%s">'."\n".'<link rel="modulepreload" href="%s">',
            $this->escapeAttr($embedUrl),
            $this->escapeAttr($presetUrl),
        );

        $pageMeta = null;
        $ssrSkipped = false;
        $outcome = SsrOutcome::Disabled;
        $reason = null;
        $durationMs = 0.0;

        if ($this->ssr === null) {
            $containerHtml = $this->emptyContainer($request);
        } else {
            $resolved = $this->ssr->resolve($request);
            $outcome = $resolved->outcome;
            $reason = $resolved->reason;
            $durationMs = $resolved->durationMs;
            if ($resolved->document !== null && $resolved->document->html !== '') {
                $containerHtml = trim($resolved->document->html);
                $pageMeta = $resolved->document->pageMeta;
            } else {
                $ssrSkipped = $outcome !== SsrOutcome::Disabled;
                $containerHtml = $this->emptyContainer($request);
            }
        }

        $boot = $this->bootScript($request, $embedUrl, $presetUrl);
        $parts = [$stylesheet, $preload];
        if ($ssrSkipped) {
            $parts[] = '<!-- mapsight-ssr-skipped -->';
        }
        $parts[] = $containerHtml;
        $parts[] = $boot;

        return new RenderedEmbed(
            implode("\n", $parts) . "\n",
            $pageMeta,
            $outcome,
            $reason,
            $durationMs,
            $stylesheet,
            $preload,
            $containerHtml,
            $boot,
        );
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

    private function bootScript(EmbedRequest $request, string $embedUrl, string $presetUrl): string
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
        $nonce = '';
        if ($request->scriptNonce !== null && $request->scriptNonce !== '') {
            $nonce = ' nonce="' . $this->escapeAttr($request->scriptNonce) . '"';
        }

        return <<<HTML
<script type="module"{$nonce}>
import {mountEmbed} from {$this->jsString($embedUrl)};
import {{$preset}} from {$this->jsString($presetUrl)};

mountEmbed({$this->jsString($request->containerId)},
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

    private function jsString(string $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT,
        );
    }
}
