<?php

namespace App\Services\Command;

/**
 * Why the LLM output could not be turned into a validation request.
 * A parse failure is never sent to the Scope Enforcer and never executed.
 */
enum ParseFailure: string
{
    case OutputTooLarge = 'output_too_large';
    case InvalidUtf8 = 'invalid_utf8';
    case InvalidJson = 'invalid_json';
    case NotAnObject = 'not_an_object';
    case MissingKeys = 'missing_keys';
    case UnexpectedKeys = 'unexpected_keys';
    case InvalidFieldType = 'invalid_field_type';
    case InvalidParameters = 'invalid_parameters';

    public function message(): string
    {
        return match ($this) {
            self::OutputTooLarge => 'LLM output is too large.',
            self::InvalidUtf8 => 'LLM output is not valid UTF-8.',
            self::InvalidJson => 'LLM output is not valid JSON.',
            self::NotAnObject => 'LLM output is not a JSON object.',
            self::MissingKeys => 'LLM output is missing required keys.',
            self::UnexpectedKeys => 'LLM output contains keys that are not allowed.',
            self::InvalidFieldType => 'LLM output has a field of the wrong type.',
            self::InvalidParameters => 'LLM output "parameters" must be a list of strings.',
        }.' The command was not sent for validation and will not be executed.';
    }
}
