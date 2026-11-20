<?php

namespace App\Services\Execution;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a command that already passed the ExecutionGate.
 *
 * - accepts ONLY AuthorizedCommand (no other way in);
 * - binary comes from a fixed allowlist, argv = [binary, ...parameters, target];
 * - no shell, minimal environment, timeout and output cap;
 * - re-checks expiry right before starting; refuses to run as root.
 *
 * Refusals here are local safety invariants, not scope decisions.
 */
final class ExecutionService
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly Config $config,
    ) {}

    public function run(AuthorizedCommand $command): ExecutionResult
    {
        $id = $command->requestId;

        try {
            $tool = $this->config->get('tools.'.$command->tool);
            if (! is_array($tool) || ! is_string($tool['binary'] ?? null)) {
                return $this->refuse($id, 'Tool "'.$command->tool.'" has no configured executable.');
            }

            $binary = $tool['binary'];
            if (! str_starts_with($binary, '/') || ! is_file($binary) || ! is_executable($binary)) {
                return $this->refuse($id, 'Configured executable for "'.$command->tool.'" is not available.');
            }

            if ($command->target === '' || str_starts_with($command->target, '-')) {
                return $this->refuse($id, 'Target must not be empty or start with "-".');
            }

            foreach ([$command->target, ...$command->parameters] as $arg) {
                if (str_contains($arg, "\0")) {
                    return $this->refuse($id, 'Arguments must not contain NUL bytes.');
                }
            }

            if (! function_exists('posix_geteuid') || posix_geteuid() === 0) {
                return $this->refuse($id, 'Refusing to execute as root (or effective user unknown).');
            }

            if (Carbon::now()->getTimestamp() >= $command->expiresAt) {
                return $this->refuse($id, 'Authorization expired before execution; validate again.');
            }

            $argv = [$binary, ...$command->parameters, $command->target];
            $timeout = (float) min((int) ($tool['timeout'] ?? 0), (int) $this->config->get('execution.max_timeout'));
            if ($timeout <= 0) {
                return $this->refuse($id, 'Invalid timeout configuration.', $argv);
            }

            $cwd = (string) $this->config->get('execution.working_directory');
            if (! is_dir($cwd) && ! @mkdir($cwd, 0700, true) && ! is_dir($cwd)) {
                return $this->refuse($id, 'Working directory is not available.', $argv);
            }

            $outcome = $this->runner->run(
                $argv,
                $timeout,
                (int) $this->config->get('execution.max_output_bytes'),
                (array) $this->config->get('execution.env'),
                $cwd,
            );

            $status = match (true) {
                $outcome->timedOut => ExecutionStatus::TimedOut,
                $outcome->outputLimitExceeded => ExecutionStatus::OutputLimitExceeded,
                default => ExecutionStatus::Completed,
            };

            return new ExecutionResult($id, $status, $argv, $outcome->exitCode, $outcome->stdout, $outcome->stderr, $outcome->durationMs);
        } catch (Throwable $e) {
            Log::error('execution.error', ['request_id' => $id, 'exception' => $e::class]);

            return new ExecutionResult($id, ExecutionStatus::Error, [], reason: 'Unexpected error during execution.');
        }
    }

    private function refuse(string $id, string $reason, array $argv = []): ExecutionResult
    {
        Log::warning('execution.refused', ['request_id' => $id, 'reason' => $reason]);

        return ExecutionResult::refused($id, $reason, $argv);
    }
}
