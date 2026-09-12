<?php

declare(strict_types=1);

namespace OpenMapsight\Embed;

/**
 * In-process breaker: open after N failures, skip SSR for a cooldown, then one try.
 */
final class ProcessSsrCircuitBreaker implements SsrCircuitBreaker
{
    private int $failures = 0;

    private ?float $openedAt = null;

    /**
     * @param \Closure(): float|null $now Clock for tests; defaults to microtime(true).
     */
    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly float $cooldownSeconds = 15.0,
        private readonly ?\Closure $now = null,
    ) {
        if ($this->failureThreshold < 1) {
            throw new \InvalidArgumentException('failureThreshold must be >= 1');
        }
        if ($this->cooldownSeconds <= 0) {
            throw new \InvalidArgumentException('cooldownSeconds must be positive');
        }
    }

    public function allow(): bool
    {
        if ($this->openedAt === null) {
            return true;
        }

        return ($this->now() - $this->openedAt) >= $this->cooldownSeconds;
    }

    public function recordSuccess(): void
    {
        $this->failures = 0;
        $this->openedAt = null;
    }

    public function recordFailure(): void
    {
        $this->failures++;
        if ($this->failures >= $this->failureThreshold) {
            $this->openedAt = $this->now();
        }
    }

    private function now(): float
    {
        return $this->now !== null ? (float) ($this->now)() : microtime(true);
    }
}
