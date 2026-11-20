<?php

namespace App\Services\Execution;

use App\Services\ScopeEnforcer\Decision;
use App\Services\ScopeEnforcer\ScopeEnforcerClient;
use App\Services\ScopeEnforcer\ValidationRequest;
use App\Services\ScopeEnforcer\ValidationResult;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Last checkpoint before execution. Produces an AuthorizedCommand only if:
 *
 *  1. the decision came from the backend, is ALLOW, execution_allowed is
 *     true and a signed authorization is present;
 *  2. the command to execute is identical to the validated one
 *     (request_id, tool, action, target, parameters incl. order);
 *  3. the authorization uses HMAC-SHA256, lives <= 60 s, is not issued in
 *     the future (beyond clock skew) and has not expired;
 *  4. the SignatureVerifier reports a valid signature over that command.
 *
 * This class makes no scope decision: it only verifies that the backend's
 * ALLOW is authentic, current and bound to exactly this command.
 */
final class ExecutionGate
{
    public function __construct(
        private readonly SignatureVerifier $verifier,
        private readonly Config $config,
    ) {}

    public function authorize(
        ValidationRequest $validated,
        ValidationResult $result,
        ValidationRequest $toExecute,
    ): GateDecision {
        try {
            return $this->check($validated, $result, $toExecute);
        } catch (Throwable) {
            return GateDecision::refused(GateRefusal::Unexpected);
        }
    }

    private function check(ValidationRequest $validated, ValidationResult $result, ValidationRequest $toExecute): GateDecision
    {
        // 1. decision
        if ($result->origin !== ValidationResult::ORIGIN_BACKEND) {
            return GateDecision::refused(GateRefusal::NotBackendDecision);
        }
        if ($result->decision !== Decision::Allow) {
            return GateDecision::refused(GateRefusal::NotAllow);
        }
        if ($result->executionAllowed !== true) {
            return GateDecision::refused(GateRefusal::ExecutionNotAllowed);
        }
        $auth = $result->authorization;
        if ($auth === null || $result->policyVersion === null) {
            return GateDecision::refused(GateRefusal::MissingAuthorization);
        }

        // 2. binding to the exact command
        if ($result->requestId !== $validated->requestId || $toExecute->requestId !== $validated->requestId) {
            return GateDecision::refused(GateRefusal::RequestIdMismatch);
        }
        if (! self::sameCommand($validated, $toExecute)) {
            return GateDecision::refused(GateRefusal::CommandMismatch);
        }

        // 3. authorization lifetime
        if ($auth->algorithm !== ScopeEnforcerClient::AUTH_ALGORITHM) {
            return GateDecision::refused(GateRefusal::UnsupportedAlgorithm);
        }
        if ($auth->expiresAt - $auth->issuedAt > (int) $this->config->get('scope_enforcer.authorization_ttl_max')
            || $auth->expiresAt <= $auth->issuedAt) {
            return GateDecision::refused(GateRefusal::TtlTooLong);
        }
        $now = Carbon::now()->getTimestamp();
        if ($auth->issuedAt > $now + (int) $this->config->get('scope_enforcer.clock_skew')) {
            return GateDecision::refused(GateRefusal::NotYetValid);
        }
        if ($now >= $auth->expiresAt) {
            return GateDecision::refused(GateRefusal::Expired);
        }

        // 4. signature over the command that will actually run
        $signature = $this->verifier->verify($toExecute, $result);
        if ($signature === SignatureCheck::Unavailable) {
            return GateDecision::refused(GateRefusal::SignatureUnavailable);
        }
        if ($signature !== SignatureCheck::Valid) {
            return GateDecision::refused(GateRefusal::SignatureInvalid);
        }

        return GateDecision::authorized(new AuthorizedCommand(
            $toExecute->requestId,
            $toExecute->tool,
            $toExecute->action,
            $toExecute->target,
            $toExecute->parameters,
            $result->policyVersion,
            $auth->expiresAt,
        ));
    }

    private static function sameCommand(ValidationRequest $a, ValidationRequest $b): bool
    {
        return $a->tool === $b->tool
            && $a->action === $b->action
            && $a->target === $b->target
            && $a->parameters === $b->parameters;
    }
}
