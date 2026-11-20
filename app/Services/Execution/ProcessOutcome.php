<?php

namespace App\Services\Execution;

final class ProcessOutcome
{
    public function __construct(
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $durationMs,
        public readonly bool $timedOut,
        public readonly bool $outputLimitExceeded,
    ) {}
}
