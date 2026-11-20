<?php

namespace App\Services\Execution;

/**
 * A command that passed every ExecutionGate check.
 *
 * ExecutionService accepts ONLY this type, so there is no execution path
 * that bypasses the gate. Instances must be created exclusively by
 * ExecutionGate (enforced by tests/Unit/ArchTest.php).
 *
 * @internal
 */
final class AuthorizedCommand
{
    /**
     * @param  list<string>  $parameters
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $tool,
        public readonly string $action,
        public readonly string $target,
        public readonly array $parameters,
        public readonly string $policyVersion,
        public readonly int $expiresAt,
    ) {}
}
