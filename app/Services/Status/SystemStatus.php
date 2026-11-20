<?php

namespace App\Services\Status;

use App\Services\Llm\LlmHealth;
use App\Services\ScopeEnforcer\HealthStatus;

final class SystemStatus
{
    public function __construct(
        public readonly HealthStatus $scopeEnforcer,
        public readonly LlmHealth $llm,
        public readonly bool $signatureVerificationAvailable,
        public readonly int $checkedAt,
    ) {}

    /** True only if a command could actually be validated AND executed right now. */
    public function executionPossible(): bool
    {
        return $this->scopeEnforcer->healthy && $this->signatureVerificationAvailable;
    }
}
