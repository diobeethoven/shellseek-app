<?php

namespace App\Services\ScopeEnforcer;

/**
 * Outcome of a validation call.
 *
 * origin = 'backend'     : decision produced by the Scope Enforcer.
 * origin = 'fail_closed' : the client could not obtain a trustworthy
 *                          decision, so it reports DENY on its own.
 *
 * Even an ALLOW from the backend is only a *candidate* for execution:
 * the ExecutionGate must still verify the signed authorization.
 */
final class ValidationResult
{
    public const ORIGIN_BACKEND = 'backend';

    public const ORIGIN_FAIL_CLOSED = 'fail_closed';

    /**
     * @param  list<string>  $reasons
     * @param  array{command_valid: bool, target_valid: bool, tool_valid: bool, parameters_valid: bool}|null  $validation
     */
    private function __construct(
        public readonly string $requestId,
        public readonly Decision $decision,
        public readonly ?string $riskLevel,
        public readonly bool $executionAllowed,
        public readonly array $reasons,
        public readonly ?array $validation,
        public readonly ?string $policyVersion,
        public readonly ?Authorization $authorization,
        public readonly string $origin,
        public readonly ?FailureCode $failureCode,
    ) {}

    /**
     * @param  list<string>  $reasons
     * @param  array{command_valid: bool, target_valid: bool, tool_valid: bool, parameters_valid: bool}  $validation
     */
    public static function fromBackend(
        string $requestId,
        Decision $decision,
        string $riskLevel,
        bool $executionAllowed,
        array $reasons,
        array $validation,
        string $policyVersion,
        ?Authorization $authorization,
    ): self {
        return new self(
            $requestId, $decision, $riskLevel, $executionAllowed, $reasons,
            $validation, $policyVersion, $authorization, self::ORIGIN_BACKEND, null,
        );
    }

    public static function failClosed(string $requestId, FailureCode $code): self
    {
        return new self(
            $requestId, Decision::Deny, null, false, [$code->message()],
            null, null, null, self::ORIGIN_FAIL_CLOSED, $code,
        );
    }

    public function isFailClosed(): bool
    {
        return $this->origin === self::ORIGIN_FAIL_CLOSED;
    }

    /**
     * True only for a well-formed backend ALLOW. This is NOT permission to
     * execute: the ExecutionGate makes the final check.
     */
    public function isAllowCandidate(): bool
    {
        return $this->origin === self::ORIGIN_BACKEND
            && $this->decision === Decision::Allow
            && $this->executionAllowed === true
            && $this->authorization !== null;
    }

    public function failureMessage(): ?string
    {
        return $this->failureCode?->message();
    }

    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'decision' => $this->decision->value,
            'risk_level' => $this->riskLevel,
            'execution_allowed' => $this->executionAllowed,
            'reasons' => $this->reasons,
            'validation' => $this->validation,
            'policy_version' => $this->policyVersion,
            'authorization' => $this->authorization?->toArray(),
            'origin' => $this->origin,
            'failure_code' => $this->failureCode?->value,
        ];
    }
}
