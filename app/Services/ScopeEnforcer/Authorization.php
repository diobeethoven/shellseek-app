<?php

namespace App\Services\ScopeEnforcer;

/**
 * Signed authorization issued by the backend on ALLOW.
 *
 * The client only checks its *shape*. Signature, lifetime and binding to
 * the command are verified by the ExecutionGate.
 */
final class Authorization
{
    public function __construct(
        public readonly int $issuedAt,
        public readonly int $expiresAt,
        public readonly string $algorithm,
        public readonly string $signature,
    ) {}

    public function toArray(): array
    {
        return [
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'algorithm' => $this->algorithm,
            'signature' => $this->signature,
        ];
    }
}
