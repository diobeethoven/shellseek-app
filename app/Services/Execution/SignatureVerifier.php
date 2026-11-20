<?php

namespace App\Services\Execution;

use App\Services\ScopeEnforcer\ValidationRequest;
use App\Services\ScopeEnforcer\ValidationResult;

/**
 * Verifies the backend's HMAC authorization over the command that is about
 * to be executed. Anything other than SignatureCheck::Valid blocks execution.
 */
interface SignatureVerifier
{
    public function verify(ValidationRequest $command, ValidationResult $result): SignatureCheck;
}
