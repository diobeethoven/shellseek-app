<?php

namespace App\Services\Llm;

/**
 * Why no command could be generated. Nothing is sent to the Scope Enforcer
 * and nothing is executed in any of these cases.
 */
enum LlmFailure: string
{
    case EmptyInstruction = 'empty_instruction';
    case InstructionTooLong = 'instruction_too_long';
    case InvalidInstructionEncoding = 'invalid_instruction_encoding';
    case NonLocalEndpoint = 'non_local_endpoint';
    case Unreachable = 'unreachable';
    case Timeout = 'timeout';
    case ModelNotFound = 'model_not_found';
    case HttpError = 'http_error';
    case InvalidResponse = 'invalid_response';
    case Incomplete = 'incomplete';
    case Unexpected = 'unexpected_error';

    public function message(): string
    {
        return match ($this) {
            self::EmptyInstruction => 'The instruction is empty.',
            self::InstructionTooLong => 'The instruction is too long.',
            self::InvalidInstructionEncoding => 'The instruction is not valid UTF-8.',
            self::NonLocalEndpoint => 'The LLM endpoint is not on loopback; refusing to send the instruction.',
            self::Unreachable => 'The local LLM (Ollama) is unreachable.',
            self::Timeout => 'The local LLM did not answer in time.',
            self::ModelNotFound => 'The configured LLM model is not available in Ollama.',
            self::HttpError => 'The local LLM returned an HTTP error.',
            self::InvalidResponse => 'The local LLM returned an invalid response.',
            self::Incomplete => 'The local LLM response was incomplete or truncated.',
            self::Unexpected => 'Unexpected error while contacting the local LLM.',
        }.' No command was generated.';
    }
}
