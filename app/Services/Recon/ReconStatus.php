<?php

namespace App\Services\Recon;

enum ReconStatus: string
{
    case HistoryError = 'history_error';
    case LlmFailed = 'llm_failed';
    case ParseFailed = 'parse_failed';
    case FailClosed = 'fail_closed';
    case Denied = 'denied';
    case WarningHeld = 'warning_held';
    case GateRefused = 'gate_refused';
    case Executed = 'executed';
    case ExecutionNotCompleted = 'execution_not_completed';
    case Error = 'error';

    /** Visual tone for the UI: ok | warn | deny | neutral. */
    public function tone(): string
    {
        return match ($this) {
            self::Executed => 'ok',
            self::WarningHeld, self::ExecutionNotCompleted => 'warn',
            self::FailClosed, self::Denied, self::GateRefused => 'deny',
            self::HistoryError, self::LlmFailed, self::ParseFailed, self::Error => 'neutral',
        };
    }

    public function executed(): bool
    {
        return $this === self::Executed || $this === self::ExecutionNotCompleted;
    }
}
