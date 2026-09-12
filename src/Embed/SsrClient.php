<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Configured sidecar client: render, purge, health, warm. Built once per process.
 */
final class SsrClient
{
    public const DEFAULT_CACHE_TTL = 3600;

    public const DEFAULT_MAX_RESPONSE_BYTES = 1048576;

    public const DEFAULT_MAX_STATE_BYTES = 262144;

    public const MAX_REQUEST_URL_BYTES = 2048;

    private const MAX_BODY_BYTES = 262144;

    /** @var list<string> */
    public const DEFAULT_SHARE_PARAMS = ['feature', 'module'];

    private readonly SsrTransport $transport;

    private readonly SsrCircuitBreaker $breaker;

    private readonly LoggerInterface $logger;

    /**
     * @param list<string> $shareParams
     */
    public function __construct(
        private readonly string $ssrUrl,
        private readonly float $timeoutSeconds = 2.0,
        private readonly float $connectTimeoutSeconds = 0.1,
        ?SsrTransport $transport = null,
        private readonly ?SsrResultCache $resultCache = null,
        private readonly int $cacheTtlSeconds = self::DEFAULT_CACHE_TTL,
        ?SsrCircuitBreaker $breaker = null,
        ?LoggerInterface $logger = null,
        private readonly int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
        private readonly int $maxStateBytes = self::DEFAULT_MAX_STATE_BYTES,
        private readonly array $shareParams = self::DEFAULT_SHARE_PARAMS,
    ) {
        if ($this->ssrUrl === '') {
            throw new \InvalidArgumentException('ssrUrl must not be empty');
        }
        if ($this->timeoutSeconds <= 0 || $this->connectTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('SSR timeouts must be positive');
        }
        if ($this->connectTimeoutSeconds > $this->timeoutSeconds) {
            throw new \InvalidArgumentException('connectTimeoutSeconds must not exceed timeoutSeconds');
        }
        if ($this->cacheTtlSeconds < 1) {
            throw new \InvalidArgumentException('cacheTtlSeconds must be >= 1');
        }
        $this->transport = $transport ?? new NativeSsrTransport();
        $this->breaker = $breaker ?? new ProcessSsrCircuitBreaker();
        $this->logger = $logger ?? new NullLogger();
    }

    public function resolve(EmbedRequest $request, bool $force = false): SsrRenderResult
    {
        $started = microtime(true);
        $requestUrl = $this->normalizeRequestUrl($request->requestUrl);
        $cacheKey = SsrCacheKey::for($request, $requestUrl);

        if (!$force) {
            $cached = $this->resultCache?->get($cacheKey);
            if ($cached !== null && $cached->html !== '') {
                $this->logger->debug('mapsight ssr cache hit', [
                    'request_id' => $request->requestId,
                    'url' => rtrim($this->ssrUrl, '/') . '/v1/render',
                ]);

                return new SsrRenderResult(
                    SsrOutcome::Cached,
                    $this->withPageMetaGate($cached, $requestUrl),
                    null,
                    $this->elapsedMs($started),
                );
            }

            if (!$this->breaker->allow()) {
                $this->logger->warning('mapsight ssr skipped', [
                    'reason' => 'breaker_open',
                    'status' => null,
                    'url' => rtrim($this->ssrUrl, '/') . '/v1/render',
                    'elapsed_ms' => $this->elapsedMs($started),
                    'request_id' => $request->requestId,
                ]);

                return new SsrRenderResult(
                    SsrOutcome::SkippedBreaker,
                    null,
                    'breaker_open',
                    $this->elapsedMs($started),
                );
            }
        }

        try {
            $document = $this->fetchDocument($request, $requestUrl);
            $this->breaker->recordSuccess();
            $this->resultCache?->set($cacheKey, $document, $this->cacheTtlSeconds);

            return new SsrRenderResult(
                SsrOutcome::Rendered,
                $this->withPageMetaGate($document, $requestUrl),
                null,
                $this->elapsedMs($started),
            );
        } catch (SsrUnavailable $error) {
            $this->noteBreakerFailure();
            $this->logSkip($request, $error, $started);

            return new SsrRenderResult(
                SsrOutcome::SkippedError,
                null,
                $error->getMessage(),
                $this->elapsedMs($started),
            );
        } catch (\Throwable $error) {
            $this->logSkip($request, $error, $started);

            return new SsrRenderResult(
                SsrOutcome::SkippedError,
                null,
                $error->getMessage(),
                $this->elapsedMs($started),
            );
        }
    }

    public function warm(EmbedRequest $request): SsrOutcome
    {
        return $this->resolve($request, true)->outcome;
    }

    public function health(): bool
    {
        try {
            $response = $this->transport->send(new SsrHttpRequest(
                'GET',
                rtrim($this->ssrUrl, '/') . '/health',
                ['Accept' => 'application/json, text/plain'],
                null,
                $this->timeoutSeconds,
                $this->connectTimeoutSeconds,
            ));

            return $response->status >= 200 && $response->status < 300;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $urls
     */
    public function purge(array $urls = []): PurgeResult
    {
        $payload = $this->purgePayload($urls);
        try {
            $json = $payload === []
                ? '{}'
                : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $response = $this->transport->send(new SsrHttpRequest(
                'POST',
                rtrim($this->ssrUrl, '/') . '/purge',
                ['Accept' => 'application/json'],
                $json,
                $this->timeoutSeconds,
                $this->connectTimeoutSeconds,
            ));
            $deleted = $this->decodePurgeResponse($response);
        } catch (\Throwable $error) {
            $this->logger->warning('mapsight ssr purge failed', [
                'reason' => $error->getMessage(),
                'url' => rtrim($this->ssrUrl, '/') . '/purge',
            ]);

            return new PurgeResult(false, []);
        }

        $this->resultCache?->flush();

        return new PurgeResult(true, $deleted);
    }

    public function normalizeRequestUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (strlen($url) > self::MAX_REQUEST_URL_BYTES) {
            $path = parse_url($url, PHP_URL_PATH);

            return is_string($path) && $path !== '' ? $path : '/';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $kept = [];
        $query = $parts['query'] ?? '';
        if ($query !== '') {
            parse_str($query, $params);
            foreach ($this->shareParams as $name) {
                $value = $params[$name] ?? null;
                if (is_string($value) && $value !== '') {
                    $kept[$name] = $value;
                }
            }
            ksort($kept);
        }

        $out = $path;
        if ($kept !== []) {
            $out .= '?' . http_build_query($kept);
        }

        return $out !== '' ? $out : null;
    }

    public function requestHasPageMetaParam(?string $normalizedRequestUrl): bool
    {
        if ($normalizedRequestUrl === null) {
            return false;
        }
        $query = parse_url($normalizedRequestUrl, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return false;
        }
        parse_str($query, $params);
        foreach ($this->shareParams as $name) {
            $value = $params[$name] ?? null;
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private function fetchDocument(EmbedRequest $request, ?string $requestUrl): SsrDocument
    {
        $payload = [
            'v' => SsrContract::VERSION,
            'preset' => $request->preset,
            'options' => $this->ssrOptions($request, $requestUrl),
        ];
        if ($request->requestId !== null && $request->requestId !== '') {
            $payload['requestId'] = $request->requestId;
        }
        if ($request->assetVersion !== null && $request->assetVersion !== '') {
            $payload['assetVersion'] = $request->assetVersion;
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SsrClientError('SSR request body is not JSON', 0, $e);
        }
        if (strlen($json) > self::MAX_BODY_BYTES) {
            throw new SsrClientError('SSR request body exceeds size cap');
        }

        $headers = ['Accept' => 'application/json'];
        if ($request->requestId !== null && $request->requestId !== '') {
            $headers['X-Request-Id'] = $request->requestId;
        }
        if ($request->assetVersion !== null && $request->assetVersion !== '') {
            $headers['X-Mapsight-Asset-Version'] = $request->assetVersion;
        }

        $url = rtrim($this->ssrUrl, '/') . '/v1/render';
        $response = $this->transport->send(new SsrHttpRequest(
            'POST',
            $url,
            $headers,
            $json,
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
        ));

        if (strlen($response->body) > $this->maxResponseBytes) {
            throw new SsrClientError('SSR response exceeds size cap');
        }
        if ($response->status >= 400 && $response->status < 500) {
            throw new SsrClientError('SSR HTTP status ' . $response->status);
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new SsrUnavailable('SSR HTTP status ' . $response->status);
        }
        if (!$this->isJsonContentType($response->contentType)) {
            throw new SsrClientError('SSR response Content-Type must be application/json');
        }

        return SsrV1Document::fromResponse(
            $response->body,
            $request->containerId,
            $this->maxStateBytes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ssrOptions(EmbedRequest $request, ?string $requestUrl): array
    {
        $options = $request->config;
        $options['containerId'] = $request->containerId;
        if ($request->containerClassName !== '') {
            $options['containerClassName'] = $request->containerClassName;
        }
        if ($requestUrl !== null) {
            $options['requestUrl'] = $requestUrl;
        }
        if ($request->pageOrigin !== null && $request->pageOrigin !== '') {
            $options['pageOrigin'] = rtrim($request->pageOrigin, '/');
        }
        if ($request->ogImage !== null && $request->ogImage !== '') {
            $options['ogImage'] = $request->ogImage;
        }
        if ($request->locale !== null && $request->locale !== '') {
            $options['locale'] = $request->locale;
        }
        if ($request->deviceClass !== null && $request->deviceClass !== '') {
            $options['deviceClass'] = $request->deviceClass;
        }

        return $options;
    }

    private function withPageMetaGate(SsrDocument $document, ?string $requestUrl): SsrDocument
    {
        if ($this->requestHasPageMetaParam($requestUrl)) {
            return $document;
        }

        return new SsrDocument($document->html, null);
    }

    /**
     * @param list<string> $urls
     * @return array<string, mixed>
     */
    private function purgePayload(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $filtered = array_values(array_filter(
            $urls,
            static fn (string $url): bool => $url !== '',
        ));
        if ($filtered === []) {
            throw new \InvalidArgumentException(
                'purge received only empty urls; refusing to purge the whole sidecar cache',
            );
        }

        return ['urls' => $filtered];
    }

    /**
     * @return list<string>
     */
    private function decodePurgeResponse(SsrHttpResponse $response): array
    {
        if ($response->status === 204) {
            return [];
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new SsrUnavailable('SSR purge HTTP status ' . $response->status);
        }

        $trimmed = trim($response->body);
        if ($trimmed === '') {
            return [];
        }

        try {
            $data = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SsrClientError('SSR purge response is not JSON', 0, $e);
        }
        if (!is_array($data) || !array_is_list($data)) {
            throw new SsrClientError('SSR purge response must be a JSON array');
        }

        $keys = [];
        foreach ($data as $item) {
            if (!is_string($item)) {
                throw new SsrClientError('SSR purge response must be a JSON string array');
            }
            $keys[] = $item;
        }

        return $keys;
    }

    private function isJsonContentType(?string $contentType): bool
    {
        if ($contentType === null || $contentType === '') {
            return false;
        }

        return str_starts_with(strtolower($contentType), 'application/json');
    }

    private function noteBreakerFailure(): void
    {
        $wasOpen = !$this->breaker->allow();
        $this->breaker->recordFailure();
        if (!$wasOpen && !$this->breaker->allow()) {
            $this->logger->info('mapsight ssr breaker opened', []);
        }
    }

    private function logSkip(EmbedRequest $request, \Throwable $error, float $started): void
    {
        $this->logger->warning('mapsight ssr skipped', [
            'reason' => $error->getMessage(),
            'status' => self::statusFromMessage($error->getMessage()),
            'url' => rtrim($this->ssrUrl, '/') . '/v1/render',
            'elapsed_ms' => $this->elapsedMs($started),
            'request_id' => $request->requestId,
        ]);
    }

    private static function statusFromMessage(string $message): ?int
    {
        if (preg_match('/SSR HTTP status (\d+)/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function elapsedMs(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 3);
    }
}
