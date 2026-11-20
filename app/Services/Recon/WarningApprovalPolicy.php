<?php

namespace App\Services\Recon;

/**
 * Extension point for a future human-approval flow for WARNING decisions.
 *
 * A WARNING can only ever be executed with a valid signed authorization
 * from the Scope Enforcer, which the backend does not issue today. Any
 * future implementation must obtain such an authorization from the backend
 * and still pass the ExecutionGate; it must never execute on its own.
 */
interface WarningApprovalPolicy
{
    public function isEnabled(): bool;
}
