<?php

namespace App\Services\Execution;

enum GateRefusal: string
{
    case NotBackendDecision = 'not_backend_decision';
    case NotAllow = 'not_allow';
    case ExecutionNotAllowed = 'execution_not_allowed';
    case MissingAuthorization = 'missing_authorization';
    case RequestIdMismatch = 'request_id_mismatch';
    case CommandMismatch = 'command_mismatch';
    case UnsupportedAlgorithm = 'unsupported_algorithm';
    case TtlTooLong = 'ttl_too_long';
    case NotYetValid = 'not_yet_valid';
    case Expired = 'expired';
    case SignatureUnavailable = 'signature_unavailable';
    case SignatureInvalid = 'signature_invalid';
    case Unexpected = 'unexpected_error';

    public function message(): string
    {
        return match ($this) {
            self::NotBackendDecision => 'No trustworthy decision from the Scope Enforcer.',
            self::NotAllow => 'The Scope Enforcer did not ALLOW this command.',
            self::ExecutionNotAllowed => 'The Scope Enforcer did not permit execution.',
            self::MissingAuthorization => 'The decision carries no signed authorization.',
            self::RequestIdMismatch => 'The authorization belongs to a different request.',
            self::CommandMismatch => 'The command differs from the one that was validated.',
            self::UnsupportedAlgorithm => 'The authorization uses an unsupported algorithm.',
            self::TtlTooLong => 'The authorization lifetime exceeds 60 seconds.',
            self::NotYetValid => 'The authorization is issued in the future.',
            self::Expired => 'The authorization has expired; the command must be validated again.',
            self::SignatureUnavailable => 'The authorization signature cannot be verified (signing verification not configured).',
            self::SignatureInvalid => 'The authorization signature is invalid.',
            self::Unexpected => 'Unexpected error while checking the authorization.',
        }.' Not executed.';
    }

    /** Only an expired authorization should trigger an automatic re-validation. */
    public function requiresRevalidation(): bool
    {
        return $this === self::Expired;
    }
}
