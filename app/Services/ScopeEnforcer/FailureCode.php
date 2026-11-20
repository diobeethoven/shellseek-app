<?php

namespace App\Services\ScopeEnforcer;

/**
 * Why the client failed closed. Every case results in DENY and no execution.
 */
enum FailureCode: string
{
    case TokenUnavailable = 'token_unavailable';
    case InvalidRequestEncoding = 'invalid_request_encoding';
    case RequestTooLarge = 'request_too_large';
    case Unreachable = 'unreachable';
    case Timeout = 'timeout';
    case Unauthorized = 'http_401';
    case PayloadTooLarge = 'http_413';
    case ServerError = 'http_500';
    case UnexpectedStatus = 'unexpected_status';
    case InvalidJson = 'invalid_json';
    case InvalidSchema = 'invalid_schema';
    case RequestIdMismatch = 'request_id_mismatch';
    case InconsistentDecision = 'inconsistent_decision';
    case Unexpected = 'unexpected_error';

    public function message(): string
    {
        return match ($this) {
            self::TokenUnavailable => 'Scope Enforcer API token is unavailable; request not sent. Treated as DENY.',
            self::InvalidRequestEncoding => 'Command could not be encoded as JSON; request not sent. Treated as DENY.',
            self::RequestTooLarge => 'Validation request exceeds 16 KB; request not sent. Treated as DENY.',
            self::Unreachable => 'Scope Enforcer is unreachable. Treated as DENY.',
            self::Timeout => 'Scope Enforcer did not respond in time. Treated as DENY.',
            self::Unauthorized => 'Scope Enforcer rejected the API token (HTTP 401). Treated as DENY.',
            self::PayloadTooLarge => 'Scope Enforcer rejected the request as too large (HTTP 413). Treated as DENY.',
            self::ServerError => 'Scope Enforcer internal error (HTTP 500). Treated as DENY.',
            self::UnexpectedStatus => 'Scope Enforcer returned an unexpected HTTP status. Treated as DENY.',
            self::InvalidJson => 'Scope Enforcer returned a response that is not valid JSON. Treated as DENY.',
            self::InvalidSchema => 'Scope Enforcer response does not match the expected schema. Treated as DENY.',
            self::RequestIdMismatch => 'Scope Enforcer response belongs to a different request. Treated as DENY.',
            self::InconsistentDecision => 'Scope Enforcer response is internally inconsistent. Treated as DENY.',
            self::Unexpected => 'Unexpected error while contacting the Scope Enforcer. Treated as DENY.',
        };
    }
}
