<?php

namespace App\Services\Recon;

use App\Models\ActivityLog;
use App\Services\Command\CommandParsingService;
use App\Services\Command\ParseResult;
use App\Services\Command\RequestIdGenerator;
use App\Services\Execution\ExecutionGate;
use App\Services\Execution\ExecutionService;
use App\Services\Execution\ExecutionStatus;
use App\Services\Execution\GateRefusal;
use App\Services\History\HistoryLogService;
use App\Services\History\HistoryWriteException;
use App\Services\Llm\LlmResult;
use App\Services\Llm\LlmService;
use App\Services\ScopeEnforcer\Decision;
use App\Services\ScopeEnforcer\ScopeEnforcerClient;
use App\Services\ScopeEnforcer\ValidationRequest;
use App\Services\ScopeEnforcer\ValidationResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates one request:
 *
 *   instruction -> LLM -> parse -> Scope Enforcer -> ExecutionGate -> execution
 *
 * Every step is recorded BEFORE the next one starts; if the history cannot be
 * written the flow stops, so nothing is ever executed without a record.
 * Laravel makes no scope decision here: it follows the backend decision and
 * the gate. WARNING is held; DENY and fail-closed stop the flow.
 */
final class ReconPipeline
{
    public function __construct(
        private readonly HistoryLogService $history,
        private readonly LlmService $llm,
        private readonly CommandParsingService $parser,
        private readonly ScopeEnforcerClient $enforcer,
        private readonly ExecutionGate $gate,
        private readonly ExecutionService $executor,
        private readonly RequestIdGenerator $ids,
        private readonly WarningApprovalPolicy $warningApproval,
    ) {}

    /**
     * @param  int|null  $userId  owner of the history row only; it is never
     *                            part of the request to the Scope Enforcer
     */
    public function run(string $instruction, ?int $userId = null): ReconOutcome
    {
        $log = null;

        try {
            $log = $this->history->start($instruction, $userId);

            // 1. LLM
            $llm = $this->llm->generateCommand($instruction);
            $this->history->recordLlm($log, $llm);
            if (! $llm->isSuccess()) {
                return new ReconOutcome(ReconStatus::LlmFailed, (string) $llm->message(), $log->id, $llm);
            }

            // 2. structure
            $parse = $this->parser->parse((string) $llm->content);
            $this->history->recordParse($log, $parse);
            if (! $parse->isSuccess()) {
                return new ReconOutcome(ReconStatus::ParseFailed, (string) $parse->message(), $log->id, $llm, $parse);
            }

            // 3. Scope Enforcer (sole decision maker)
            $request = $parse->request;
            $validation = $this->enforcer->validate($request);
            $this->history->recordValidation($log, $validation);

            $stop = $this->stopUnlessAllow($validation, $log, $llm, $parse, $request);
            if ($stop !== null) {
                return $stop;
            }

            // 4. gate; an expired authorization gets exactly one re-validation
            $gate = $this->gate->authorize($request, $validation, $request);
            $revalidated = false;

            if (! $gate->isAuthorized() && $gate->refusal === GateRefusal::Expired) {
                $revalidated = true;
                $request = new ValidationRequest(
                    $this->ids->generate(), $request->tool, $request->action, $request->target, $request->parameters,
                );
                $this->history->recordRevalidation($log, $request->requestId);
                $validation = $this->enforcer->validate($request);
                $this->history->recordValidation($log, $validation);

                $stop = $this->stopUnlessAllow($validation, $log, $llm, $parse, $request, true);
                if ($stop !== null) {
                    return $stop;
                }
                $gate = $this->gate->authorize($request, $validation, $request);
            }

            // recorded before execution: no unrecorded execution is possible
            $this->history->recordGate($log, $gate);
            if (! $gate->isAuthorized()) {
                return new ReconOutcome(
                    ReconStatus::GateRefused, $gate->refusal->message(), $log->id,
                    $llm, $parse, $request, $validation, $revalidated, $gate,
                );
            }

            // 5. execution
            $execution = $this->executor->run($gate->command);
            $this->history->recordExecution($log, $execution);

            $status = $execution->status === ExecutionStatus::Completed ? ReconStatus::Executed : ReconStatus::ExecutionNotCompleted;
            $message = match ($execution->status) {
                ExecutionStatus::Completed => 'Command executed.',
                ExecutionStatus::TimedOut => 'Command stopped: timeout reached.',
                ExecutionStatus::OutputLimitExceeded => 'Command stopped: output limit reached.',
                default => (string) ($execution->reason ?? 'Command was not executed.'),
            };

            return new ReconOutcome($status, $message, $log->id, $llm, $parse, $request, $validation, $revalidated, $gate, $execution);
        } catch (HistoryWriteException) {
            return new ReconOutcome(
                ReconStatus::HistoryError,
                'Activity history could not be written. For safety nothing was executed.',
                $log?->id,
            );
        } catch (Throwable $e) {
            Log::error('recon.pipeline_error', ['exception' => $e::class]);

            return new ReconOutcome(ReconStatus::Error, 'Unexpected error. Nothing was executed.', $log?->id);
        }
    }

    private function stopUnlessAllow(
        ValidationResult $validation,
        ActivityLog $log,
        LlmResult $llm,
        ParseResult $parse,
        ValidationRequest $request,
        bool $revalidated = false,
    ): ?ReconOutcome {
        if ($validation->isFailClosed()) {
            return new ReconOutcome(
                ReconStatus::FailClosed, (string) $validation->failureMessage(), $log->id,
                $llm, $parse, $request, $validation, $revalidated,
            );
        }

        if ($validation->decision === Decision::Warning) {
            return new ReconOutcome(
                ReconStatus::WarningHeld,
                'WARNING: execution is held. Human approval is not available in this version; the command was not executed.',
                $log->id, $llm, $parse, $request, $validation, $revalidated,
                warningApprovalEnabled: $this->warningApproval->isEnabled(),
            );
        }

        if ($validation->decision !== Decision::Allow) {
            return new ReconOutcome(
                ReconStatus::Denied, 'DENY: the Scope Enforcer rejected this command. It was not executed.', $log->id,
                $llm, $parse, $request, $validation, $revalidated,
            );
        }

        return null;
    }
}
