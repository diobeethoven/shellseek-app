<?php

namespace App\Services\Recon;

/**
 * Current behaviour: WARNING is held and never executed.
 */
final class DisabledWarningApproval implements WarningApprovalPolicy
{
    public function isEnabled(): bool
    {
        return false;
    }
}
