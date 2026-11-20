<?php

namespace App\Services\Execution;

use App\Services\ScopeEnforcer\ValidationRequest;
use App\Services\ScopeEnforcer\ValidationResult;

/**
 * Placeholder until the real HMAC verifier exists (stage 3: requires Dio's
 * signing.py, test vectors and the signing secret file).
 *
 * Always reports Unavailable, so NOTHING can be executed (fail-closed).
 */
final class UnavailableSignatureVerifier implements SignatureVerifier
{
    public function verify(ValidationRequest $command, ValidationResult $result): SignatureCheck
    {
        return SignatureCheck::Unavailable;
    }
}
