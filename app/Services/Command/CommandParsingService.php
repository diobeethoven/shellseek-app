<?php

namespace App\Services\Command;

use App\Services\ScopeEnforcer\ValidationRequest;
use JsonException;
use stdClass;

/**
 * Turns raw (untrusted) LLM output into a ValidationRequest.
 *
 * Checks STRUCTURE ONLY: a pure JSON object with exactly the keys
 * tool, action, target (strings) and parameters (list of strings).
 *
 * It deliberately does NOT "fix" anything (no trimming, lowercasing,
 * splitting "-p80", type coercion, markdown extraction, defaults) and does
 * NOT apply any policy (tool lists, regexes, targets). Whatever the LLM
 * produced is forwarded as-is so that the Scope Enforcer — the only
 * decision maker — judges it and its reasons are recorded.
 */
final class CommandParsingService
{
    public const MAX_OUTPUT_BYTES = 8192;

    public const EXCERPT_BYTES = 2048;

    public const REQUIRED_KEYS = ['tool', 'action', 'target', 'parameters'];

    public function __construct(private readonly RequestIdGenerator $ids) {}

    public function parse(string $llmOutput): ParseResult
    {
        $requestId = $this->ids->generate();
        $excerpt = self::excerpt($llmOutput);
        $fail = fn (ParseFailure $f, ?string $detail = null) => ParseResult::failure($requestId, $f, $detail, $excerpt);

        if (strlen($llmOutput) > self::MAX_OUTPUT_BYTES) {
            return $fail(ParseFailure::OutputTooLarge, strlen($llmOutput).' bytes');
        }

        if (! mb_check_encoding($llmOutput, 'UTF-8')) {
            return $fail(ParseFailure::InvalidUtf8);
        }

        try {
            // Objects decode to stdClass so a JSON list can never pass as an object.
            $decoded = json_decode($llmOutput, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $fail(ParseFailure::InvalidJson);
        }

        if (! $decoded instanceof stdClass) {
            return $fail(ParseFailure::NotAnObject);
        }

        $data = get_object_vars($decoded);
        $keys = array_map('strval', array_keys($data));

        $missing = array_values(array_diff(self::REQUIRED_KEYS, $keys));
        if ($missing !== []) {
            return $fail(ParseFailure::MissingKeys, self::describeKeys($missing));
        }

        $unexpected = array_values(array_diff($keys, self::REQUIRED_KEYS));
        if ($unexpected !== []) {
            return $fail(ParseFailure::UnexpectedKeys, self::describeKeys($unexpected));
        }

        foreach (['tool', 'action', 'target'] as $field) {
            if (! is_string($data[$field])) {
                return $fail(ParseFailure::InvalidFieldType, $field.' must be a string');
            }
        }

        $parameters = $data['parameters'];
        if (! is_array($parameters) || ! array_is_list($parameters)) {
            return $fail(ParseFailure::InvalidParameters);
        }
        foreach ($parameters as $index => $parameter) {
            if (! is_string($parameter)) {
                return $fail(ParseFailure::InvalidParameters, 'item '.$index.' is '.get_debug_type($parameter));
            }
        }

        return ParseResult::success(
            new ValidationRequest($requestId, $data['tool'], $data['action'], $data['target'], $parameters),
            $excerpt,
        );
    }

    /**
     * Bounded, valid-UTF-8 excerpt of the raw output for the history log.
     */
    public static function excerpt(string $raw): string
    {
        $cut = substr($raw, 0, self::EXCERPT_BYTES);

        return mb_scrub($cut, 'UTF-8').(strlen($raw) > self::EXCERPT_BYTES ? '…[truncated]' : '');
    }

    /**
     * Names of offending keys only (max 5, each max 32 chars), never values.
     *
     * @param  list<string>  $keys
     */
    private static function describeKeys(array $keys): string
    {
        $shown = array_map(
            fn (string $k) => json_encode(mb_strimwidth(mb_scrub($k, 'UTF-8'), 0, 32, '…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            array_slice($keys, 0, 5),
        );

        return implode(', ', $shown).(count($keys) > 5 ? ', …' : '');
    }
}
