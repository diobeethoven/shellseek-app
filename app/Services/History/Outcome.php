<?php

namespace App\Services\History;

/**
 * Summary of how far a request got and how it ended.
 */
enum Outcome: string
{
    case InProgress = 'in_progress';
    case LlmFailed = 'llm_failed';
    case ParseFailed = 'parse_failed';
    case FailClosed = 'fail_closed';
    case Denied = 'denied';
    case Warning = 'warning';
    case Allowed = 'allowed';
    case GateRefused = 'gate_refused';
    case Authorized = 'authorized';
    case Executed = 'executed';
    case TimedOut = 'timed_out';
    case OutputLimitExceeded = 'output_limit_exceeded';
    case ExecutionRefused = 'execution_refused';
    case ExecutionError = 'execution_error';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::LlmFailed => 'LLM failed',
            self::ParseFailed => 'Invalid LLM output',
            self::FailClosed => 'Fail-closed (DENY)',
            self::Denied => 'Denied',
            self::Warning => 'Warning – held',
            self::Allowed => 'Allowed',
            self::GateRefused => 'Gate refused',
            self::Authorized => 'Authorized',
            self::Executed => 'Executed',
            self::TimedOut => 'Timed out',
            self::OutputLimitExceeded => 'Output limit',
            self::ExecutionRefused => 'Execution refused',
            self::ExecutionError => 'Execution error',
        };
    }

    /** Visual tone for the UI: ok | warn | deny | neutral. */
    public function tone(): string
    {
        return match ($this) {
            self::Executed, self::Allowed, self::Authorized => 'ok',
            self::Warning, self::TimedOut, self::OutputLimitExceeded => 'warn',
            self::FailClosed, self::Denied, self::GateRefused, self::ExecutionRefused, self::ExecutionError => 'deny',
            self::InProgress, self::LlmFailed, self::ParseFailed => 'neutral',
        };
    }

    /**
     * True once the pipeline can no longer write to the row. InProgress,
     * Allowed (gate pending) and Authorized (execution pending) are still
     * running, so such rows cannot be archived or deleted.
     */
    public function isFinal(): bool
    {
        return ! in_array($this, [self::InProgress, self::Allowed, self::Authorized], true);
    }
}
