<?php

declare(strict_types=1);

namespace OpenMapsight\Embed\Testing;

use OpenMapsight\Embed\SsrContract;
use OpenMapsight\Embed\SsrHttpRequest;
use OpenMapsight\Embed\SsrHttpResponse;
use OpenMapsight\Embed\SsrTransport;

/**
 * Queued responses or exceptions for host and library tests.
 */
final class FakeSsrTransport implements SsrTransport
{
    /** @var list<SsrHttpResponse|\Throwable> */
    private array $queue = [];

    /** @var list<SsrHttpRequest> */
    public array $requests = [];

    public function queue(SsrHttpResponse|\Throwable $next): void
    {
        $this->queue[] = $next;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $pageMeta
     */
    public function queueV1(
        string $html,
        array $state = [],
        ?array $pageMeta = null,
        int $status = 200,
        string $contentType = 'application/json',
    ): void {
        $this->queue(new SsrHttpResponse(
            $status,
            $contentType,
            json_encode([
                'v' => SsrContract::VERSION,
                'html' => $html,
                'state' => $state,
                'pageMeta' => $pageMeta,
            ], JSON_THROW_ON_ERROR),
        ));
    }

    public function send(SsrHttpRequest $request): SsrHttpResponse
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \RuntimeException('FakeSsrTransport queue is empty');
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
