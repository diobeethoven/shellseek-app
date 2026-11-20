<?php

namespace App\Services\Recon;

use App\Services\Command\ParseResult;
use App\Services\Execution\ExecutionResult;
use App\Services\Execution\GateDecision;
use App\Services\Llm\LlmResult;
use App\Services\ScopeEnforcer\ValidationRequest;
use App\Services\ScopeEnforcer\ValidationResult;

/**
 * Everything the UI needs to show for one request.
 */
final class ReconOutcome
{
    public function __construct(
        public readonly ReconStatus $status,
        public readonly string $message,
        public readonly ?int $logId = null,
        public readonly ?LlmResult $llm = null,
        public readonly ?ParseResult $parse = null,
        public readonly ?ValidationRequest $validatedRequest = null,
        public readonly ?ValidationResult $validation = null,
        public readonly bool $revalidated = false,
        public readonly ?GateDecision $gate = null,
        public readonly ?ExecutionResult $execution = null,
        public readonly bool $warningApprovalEnabled = false,
    ) {}
}
