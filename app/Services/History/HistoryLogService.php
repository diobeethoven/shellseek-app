<?php

namespace App\Services\History;

use App\Models\ActivityLog;
use App\Services\Command\ParseResult;
use App\Services\Execution\ExecutionResult;
use App\Services\Execution\ExecutionStatus;
use App\Services\Execution\GateDecision;
use App\Services\Llm\LlmResult;
use App\Services\ScopeEnforcer\Decision;
use App\Services\ScopeEnforcer\ValidationResult;
use Throwable;

/**
 * Records every request in activity_logs (one row, updated per stage).
 *
 * Every text/array value passes through SecretMasker before it is written.
 * Any write failure raises HistoryWriteException; the caller must then stop
 * and must not execute anything.
 */
class HistoryLogService
{
    public const MAX_TEXT_BYTES = 4096;

    public const MAX_OUTPUT_BYTES = 65536;

    public function __construct(private readonly SecretMasker $masker) {}

    /**
     * @param  int|null  $userId  local account that made the request (stored
     *                            here only; never sent to the Scope Enforcer)
     */
    public function start(string $instruction, ?int $userId = null): ActivityLog
    {
        return $this->write((new ActivityLog)->forceFill(['user_id' => $userId]), [
            'instruction' => $this->text($instruction, self::MAX_TEXT_BYTES),
            'stage' => 'llm',
            'outcome' => Outcome::InProgress->value,
        ]);
    }

    public function recordLlm(ActivityLog $log, LlmResult $result): ActivityLog
    {
        return $this->write($log, [
            'stage' => 'llm',
            'outcome' => ($result->isSuccess() ? Outcome::InProgress : Outcome::LlmFailed)->value,
            'llm_model' => $result->model,
            'llm_output' => $this->text($result->content, self::MAX_TEXT_BYTES),
            'llm_failure' => $result->failure?->value,
            'llm_duration_ms' => $result->durationMs,
        ]);
    }

    public function recordParse(ActivityLog $log, ParseResult $result): ActivityLog
    {
        $request = $result->request;

        return $this->write($log, [
            'stage' => 'parse',
            'outcome' => ($result->isSuccess() ? Outcome::InProgress : Outcome::ParseFailed)->value,
            'request_id' => $result->requestId,
            'parse_failure' => $result->failure?->value,
            'parse_detail' => $this->text($result->message(), self::MAX_TEXT_BYTES),
            'tool' => $this->text($request?->tool, self::MAX_TEXT_BYTES),
            'action' => $this->text($request?->action, self::MAX_TEXT_BYTES),
            'target' => $this->text($request?->target, self::MAX_TEXT_BYTES),
            'parameters' => $this->masker->maskArray($request?->parameters),
        ]);
    }

    public function recordValidation(ActivityLog $log, ValidationResult $result): ActivityLog
    {
        $outcome = match (true) {
            $result->isFailClosed() => Outcome::FailClosed,
            $result->decision === Decision::Allow => Outcome::Allowed,
            $result->decision === Decision::Warning => Outcome::Warning,
            default => Outcome::Denied,
        };

        return $this->write($log, [
            'stage' => 'validate',
            'outcome' => $outcome->value,
            'decision' => $result->decision->value,
            'risk_level' => $result->riskLevel,
            'execution_allowed' => $result->executionAllowed,
            'reasons' => $this->masker->maskArray($result->reasons),
            'validation' => $result->validation,
            'policy_version' => $this->text($result->policyVersion, 64),
            'origin' => $result->origin,
            'failure_code' => $result->failureCode?->value,
            'authorization_issued_at' => $result->authorization?->issuedAt,
            'authorization_expires_at' => $result->authorization?->expiresAt,
        ]);
    }

    /**
     * The authorization expired before execution and the identical command
     * was validated again under a new request_id.
     */
    public function recordRevalidation(ActivityLog $log, string $newRequestId): ActivityLog
    {
        return $this->write($log, [
            'original_request_id' => $log->original_request_id ?? $log->request_id,
            'request_id' => $newRequestId,
            'revalidated' => true,
        ]);
    }

    public function recordGate(ActivityLog $log, GateDecision $decision): ActivityLog
    {
        return $this->write($log, [
            'stage' => 'gate',
            'outcome' => ($decision->isAuthorized() ? Outcome::Authorized : Outcome::GateRefused)->value,
            'gate_refusal' => $decision->refusal?->value,
        ]);
    }

    public function recordExecution(ActivityLog $log, ExecutionResult $result): ActivityLog
    {
        $outcome = match ($result->status) {
            ExecutionStatus::Completed => Outcome::Executed,
            ExecutionStatus::TimedOut => Outcome::TimedOut,
            ExecutionStatus::OutputLimitExceeded => Outcome::OutputLimitExceeded,
            ExecutionStatus::Refused => Outcome::ExecutionRefused,
            ExecutionStatus::Error => Outcome::ExecutionError,
        };

        return $this->write($log, [
            'stage' => 'execute',
            'outcome' => $outcome->value,
            'execution_status' => $result->status->value,
            'exit_code' => $result->exitCode,
            'argv' => $this->masker->maskArray($result->argv),
            'stdout' => $this->text($result->stdout, self::MAX_OUTPUT_BYTES),
            'stderr' => $this->text($result->stderr, self::MAX_OUTPUT_BYTES),
            'execution_duration_ms' => $result->durationMs,
            'execution_reason' => $this->text($result->reason, self::MAX_TEXT_BYTES),
        ]);
    }

    private function write(ActivityLog $log, array $attributes): ActivityLog
    {
        try {
            $log->fill($attributes)->save();
        } catch (Throwable $e) {
            throw new HistoryWriteException('Activity history could not be written ('.$e::class.').', 0, $e);
        }

        // An UPDATE on a row deleted in the meantime affects 0 rows without an
        // error. Treat a vanished row as a write failure so the caller never
        // continues (and never executes) without a history record.
        try {
            $stillThere = $log->newQuery()->whereKey($log->getKey())->exists();
        } catch (Throwable $e) {
            throw new HistoryWriteException('Activity history could not be written ('.$e::class.').', 0, $e);
        }
        if (! $stillThere) {
            throw new HistoryWriteException('Activity history row no longer exists.');
        }

        return $log;
    }

    /**
     * Mask secrets first (on the full text), then cut to a valid-UTF-8 prefix.
     */
    private function text(?string $value, int $maxBytes): ?string
    {
        if ($value === null) {
            return null;
        }

        $masked = (string) $this->masker->mask($value);
        if (strlen($masked) <= $maxBytes) {
            return mb_scrub($masked, 'UTF-8');
        }

        return mb_scrub(substr($masked, 0, $maxBytes), 'UTF-8').'…[truncated]';
    }
}
