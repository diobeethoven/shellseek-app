<?php

namespace App\Services\Execution;

final class ExecutionResult
{
    /**
     * @param  list<string>  $argv
     */
    public function __construct(
        public readonly string $requestId,
        public readonly ExecutionStatus $status,
        public readonly array $argv,
        public readonly ?int $exitCode = null,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        public readonly ?int $durationMs = null,
        public readonly ?string $reason = null,
    ) {}

    public static function refused(string $requestId, string $reason, array $argv = []): self
    {
        return new self($requestId, ExecutionStatus::Refused, $argv, reason: $reason);
    }

    public function wasStarted(): bool
    {
        return ! in_array($this->status, [ExecutionStatus::Refused, ExecutionStatus::Error], true);
    }
}
