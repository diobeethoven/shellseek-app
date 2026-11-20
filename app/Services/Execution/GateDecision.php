<?php

namespace App\Services\Execution;

final class GateDecision
{
    private function __construct(
        public readonly ?AuthorizedCommand $command,
        public readonly ?GateRefusal $refusal,
    ) {}

    public static function authorized(AuthorizedCommand $command): self
    {
        return new self($command, null);
    }

    public static function refused(GateRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function isAuthorized(): bool
    {
        return $this->command !== null;
    }
}
