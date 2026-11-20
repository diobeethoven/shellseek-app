<?php

namespace App\Services\ScopeEnforcer;

/**
 * Result of GET /health. Purely informational for the System Status page;
 * it never influences whether a command may run.
 */
final class HealthStatus
{
    public function __construct(
        public readonly bool $reachable,
        public readonly bool $healthy,
        public readonly ?int $httpStatus,
        public readonly ?string $status,
        public readonly ?int $latencyMs,
        public readonly string $message,
    ) {}
}
