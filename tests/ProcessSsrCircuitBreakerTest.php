<?php

declare(strict_types=1);

namespace OpenMapsight\Tests;

use OpenMapsight\Embed\ProcessSsrCircuitBreaker;
use PHPUnit\Framework\TestCase;

final class ProcessSsrCircuitBreakerTest extends TestCase
{
    public function test_success_after_cooldown_resets(): void
    {
        $clock = new class {
            public float $now = 0.0;
        };
        $breaker = new ProcessSsrCircuitBreaker(1, 10.0, fn () => $clock->now);

        $breaker->recordFailure();
        $this->assertFalse($breaker->allow());

        $clock->now = 10.0;
        $this->assertTrue($breaker->allow());

        $breaker->recordSuccess();
        $this->assertTrue($breaker->allow());
        $clock->now = 10.1;
        $this->assertTrue($breaker->allow());
    }

    public function test_failure_after_cooldown_reopens_immediately(): void
    {
        $clock = new class {
            public float $now = 0.0;
        };
        $breaker = new ProcessSsrCircuitBreaker(1, 10.0, fn () => $clock->now);

        $breaker->recordFailure();
        $clock->now = 10.0;
        $this->assertTrue($breaker->allow());

        $breaker->recordFailure();
        $this->assertFalse($breaker->allow());
    }
}
