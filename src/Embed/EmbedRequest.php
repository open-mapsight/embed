<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * One Mapsight placement: caller-owned embed config plus host asset/SSR knobs.
 */
final class EmbedRequest
{
    /** @var list<string> */
    private const JS_RESERVED_WORDS = [
        'await', 'break', 'case', 'catch', 'class', 'const', 'continue',
        'debugger', 'default', 'delete', 'do', 'else', 'enum', 'export',
        'extends', 'false', 'finally', 'for', 'function', 'if', 'implements',
        'import', 'in', 'instanceof', 'interface', 'let', 'new', 'null',
        'package', 'private', 'protected', 'public', 'return', 'static',
        'super', 'switch', 'this', 'throw', 'true', 'try', 'typeof', 'var',
        'void', 'while', 'with', 'yield',
    ];

    /**
     * @param array<string, mixed> $config Arguments for the preset factory (e.g. infosite({…})).
     */
    public function __construct(
        public readonly string $preset,
        public readonly string $containerId,
        public readonly array $config,
        public readonly string $assetBase = '/mapsight/plan',
        public readonly string $containerClassName = '',
        public readonly ?string $ssrUrl = null,
        public readonly float $ssrTimeoutSeconds = 2.0,
        public readonly float $ssrConnectTimeoutSeconds = 0.1,
        public readonly ?string $requestId = null,
        public readonly ?string $assetVersion = null,
        public readonly ?string $locale = null,
        public readonly ?string $deviceClass = null,
        public readonly ?string $requestUrl = null,
        public readonly ?string $pageOrigin = null,
        public readonly ?string $ogImage = null,
    ) {
        self::assertJsIdentifier($this->preset, 'preset');
        if ($this->containerId === '') {
            throw new \InvalidArgumentException('containerId must not be empty');
        }
        if ($this->ssrTimeoutSeconds <= 0 || $this->ssrConnectTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('SSR timeouts must be positive');
        }
        if ($this->ssrConnectTimeoutSeconds > $this->ssrTimeoutSeconds) {
            throw new \InvalidArgumentException('ssrConnectTimeoutSeconds must not exceed ssrTimeoutSeconds');
        }
        self::assertHeaderToken($this->requestId, 'requestId');
        self::assertHeaderToken($this->assetVersion, 'assetVersion');
    }

    /**
     * Preset is interpolated as a JS import binding and a `/assets/{preset}.js`
     * file name. Hyphens and reserved words are a SyntaxError in the boot script.
     */
    private static function assertJsIdentifier(string $value, string $field): void
    {
        if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be a JavaScript identifier');
        }
        if (in_array($value, self::JS_RESERVED_WORDS, true)) {
            throw new \InvalidArgumentException($field . ' must not be a JavaScript reserved word');
        }
    }

    /**
     * Values that become HTTP headers (and query tokens). Reject CR/LF and
     * anything outside a conservative token alphabet.
     */
    private static function assertHeaderToken(?string $value, string $field): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,200}$/', $value) !== 1) {
            throw new \InvalidArgumentException(
                $field . ' must match [A-Za-z0-9._-]{1,200}',
            );
        }
    }

    /**
     * Page URL Node SSR uses to select Stadtplan `?module=` and apply `?feature=`.
     * Explicit field, then config.requestUrl, then the current SAPI request URI.
     */
    public function resolvedRequestUrl(): ?string
    {
        if ($this->requestUrl !== null && $this->requestUrl !== '') {
            return $this->requestUrl;
        }

        $fromConfig = $this->config['requestUrl'] ?? null;
        if (is_string($fromConfig) && $fromConfig !== '') {
            return $fromConfig;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? null;
        if (is_string($uri) && $uri !== '') {
            return $uri;
        }

        return null;
    }

    /**
     * Public page origin (`https://www.example.com`) so path-only
     * `requestUrl` can become an absolute canonical / `og:url`.
     */
    public function resolvedPageOrigin(): ?string
    {
        if ($this->pageOrigin !== null && $this->pageOrigin !== '') {
            return rtrim($this->pageOrigin, '/');
        }

        $fromConfig = $this->config['pageOrigin'] ?? null;
        if (is_string($fromConfig) && $fromConfig !== '') {
            return rtrim($fromConfig, '/');
        }

        $fromEnv = getenv('MAPSIGHT_PAGE_ORIGIN');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }

        $publicHost = getenv('PUBLIC_HOST');
        if (is_string($publicHost) && $publicHost !== '') {
            return 'https://' . $publicHost;
        }

        return null;
    }

    /**
     * Absolute or root-absolute static default OG card. Null lets the sidecar
     * use `{pageOrigin}/plan/img/og-default.png`.
     */
    public function resolvedOgImage(): ?string
    {
        if ($this->ogImage !== null && $this->ogImage !== '') {
            return $this->ogImage;
        }

        $fromConfig = $this->config['ogImage'] ?? null;
        if (is_string($fromConfig) && $fromConfig !== '') {
            return $fromConfig;
        }

        $fromEnv = getenv('MAPSIGHT_OG_IMAGE');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        return null;
    }

    /**
     * Head overrides apply only when `?feature=` is on the request URL
     * (`REQUEST_URI`), not when something is selected only in the client.
     */
    public function requestHasFeatureParam(): bool
    {
        return $this->requestHasQueryParam('feature');
    }

    /**
     * Head overrides apply when `?feature=` or Stadtplan `?module=` is on
     * the request URL, not when a topic is selected only in the client.
     */
    public function requestHasPageMetaParam(): bool
    {
        return $this->requestHasQueryParam('feature')
            || $this->requestHasQueryParam('module');
    }

    private function requestHasQueryParam(string $name): bool
    {
        $url = $this->resolvedRequestUrl();
        if ($url === null) {
            return false;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return false;
        }

        parse_str($query, $params);
        $value = $params[$name] ?? null;

        return is_string($value) && $value !== '';
    }
}
