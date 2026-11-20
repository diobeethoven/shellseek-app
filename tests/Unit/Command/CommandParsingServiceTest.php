<?php

use App\Services\Command\CommandParsingService;
use App\Services\Command\ParseFailure;
use App\Services\Command\RequestIdGenerator;
use App\Services\ScopeEnforcer\ValidationRequest;

/*
| Pure unit tests: no network, no Ollama, no Scope Enforcer.
| Target values are examples only (policy placeholder / example.com).
*/

const BACKEND_REQUEST_ID_REGEX = '/\A[A-Za-z0-9_-]{1,64}\z/';

function parser(): CommandParsingService
{
    return new CommandParsingService(new RequestIdGenerator);
}

function llmJson(array $overrides = []): string
{
    return json_encode(array_replace([
        'tool' => 'nmap',
        'action' => 'service_detection',
        'target' => '192.168.100.50',
        'parameters' => ['-sV', '-p', '80,443'],
    ], $overrides), JSON_UNESCAPED_SLASHES);
}

// ------------------------------------------------------------- success

it('builds a validation request from a well-formed nmap command', function () {
    $result = parser()->parse(llmJson());

    expect($result->isSuccess())->toBeTrue()
        ->and($result->failure)->toBeNull()
        ->and($result->request)->toBeInstanceOf(ValidationRequest::class)
        ->and($result->request->tool)->toBe('nmap')
        ->and($result->request->action)->toBe('service_detection')
        ->and($result->request->target)->toBe('192.168.100.50')
        ->and($result->request->parameters)->toBe(['-sV', '-p', '80,443'])
        ->and($result->request->requestId)->toBe($result->requestId)
        ->and($result->requestId)->toMatch(BACKEND_REQUEST_ID_REGEX)
        ->and($result->requestId)->toStartWith('REQ-');
});

it('accepts whois with an empty parameter list', function () {
    $result = parser()->parse(llmJson(['tool' => 'whois', 'action' => 'domain_lookup', 'target' => 'example.com', 'parameters' => []]));

    expect($result->isSuccess())->toBeTrue()
        ->and($result->request->parameters)->toBe([]);
});

it('accepts surrounding whitespace and any key order (still pure JSON)', function () {
    $raw = "\n  {\"parameters\":[\"-sV\"],\"target\":\"192.168.100.50\",\"action\":\"service_detection\",\"tool\":\"nmap\"}\n";

    expect(parser()->parse($raw)->isSuccess())->toBeTrue();
});

it('forwards values exactly as produced, without fixing them', function (array $overrides) {
    $result = parser()->parse(llmJson($overrides));

    expect($result->isSuccess())->toBeTrue();
    foreach ($overrides as $field => $value) {
        expect($result->request->{$field})->toBe($value);
    }
})->with([
    'unknown tool dig' => [['tool' => 'dig', 'action' => 'dns_lookup']],
    'forbidden -A' => [['parameters' => ['-sV', '-A']]],
    'shell metacharacters' => [['parameters' => ['-sV;id', '$(whoami)', '`x`', '|', '&&']]],
    'joined -p80 not split' => [['parameters' => ['-sV', '-p80']]],
    'uppercase tool not lowercased' => [['tool' => 'NMAP']],
    'padded target not trimmed' => [['target' => ' 192.168.100.50 ']],
    'empty strings kept' => [['tool' => '', 'action' => '', 'target' => '', 'parameters' => ['']]],
    'duplicates kept' => [['parameters' => ['-sV', '-sV', '-p', '80', '-p', '80']]],
    'out-of-scope target' => [['target' => '8.8.8.8']],
    'unicode kept' => [['target' => 'exämple.com']],
]);

// -------------------------------------------------------------- failures

it('rejects output that is not pure JSON', function (string $raw) {
    $result = parser()->parse($raw);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->request)->toBeNull()
        ->and($result->failure)->toBe(ParseFailure::InvalidJson);
})->with([
    'empty' => '',
    'prose' => 'Sure! Here is the command: nmap -sV 192.168.100.50',
    'markdown fence' => "```json\n".llmJson()."\n```",
    'prose then json' => 'Command: '.llmJson(),
    'json then prose' => llmJson().' Hope this helps!',
    'two objects' => llmJson().llmJson(),
    'single quotes' => "{'tool':'nmap'}",
    'trailing comma' => '{"tool":"nmap","action":"a","target":"t","parameters":[],}',
    'raw command string' => 'nmap -sV -p 80 192.168.100.50',
]);

it('rejects JSON that is not an object', function (string $raw) {
    expect(parser()->parse($raw)->failure)->toBe(ParseFailure::NotAnObject);
})->with([
    'null' => 'null',
    'string' => '"nmap -sV 192.168.100.50"',
    'number' => '42',
    'bool' => 'true',
    'empty list' => '[]',
    'list of command' => '['.llmJson().']',
]);

it('rejects objects with missing keys', function (string $raw, string $mentions) {
    $result = parser()->parse($raw);

    expect($result->failure)->toBe(ParseFailure::MissingKeys)
        ->and($result->message())->toContain($mentions);
})->with([
    'empty object' => ['{}', '"tool"'],
    'no parameters' => ['{"tool":"nmap","action":"service_detection","target":"192.168.100.50"}', '"parameters"'],
    'no target' => ['{"tool":"nmap","action":"service_detection","parameters":[]}', '"target"'],
]);

it('rejects extra keys, including attempts to set request_id or requested_by', function (string $key) {
    $data = json_decode(llmJson(), true);
    $data[$key] = 'x';
    $result = parser()->parse(json_encode($data));

    expect($result->failure)->toBe(ParseFailure::UnexpectedKeys)
        ->and($result->message())->toContain('"'.$key.'"');
})->with(['request_id', 'requested_by', 'explanation', 'policy', 'execution_allowed']);

it('rejects wrong field types without coercion', function (array $overrides, ParseFailure $expected) {
    expect(parser()->parse(llmJson($overrides))->failure)->toBe($expected);
})->with([
    'tool null' => [['tool' => null], ParseFailure::InvalidFieldType],
    'tool number' => [['tool' => 1], ParseFailure::InvalidFieldType],
    'action list' => [['action' => ['service_detection']], ParseFailure::InvalidFieldType],
    'target object' => [['target' => ['ip' => '192.168.100.50']], ParseFailure::InvalidFieldType],
    'target bool' => [['target' => true], ParseFailure::InvalidFieldType],
    'parameters string' => [['parameters' => '-sV -p 80'], ParseFailure::InvalidParameters],
    'parameters null' => [['parameters' => null], ParseFailure::InvalidParameters],
    'parameters object' => [['parameters' => ['p' => '80']], ParseFailure::InvalidParameters],
    'port as int' => [['parameters' => ['-sV', '-p', 80]], ParseFailure::InvalidParameters],
    'nested array' => [['parameters' => ['-sV', ['-p', '80']]], ParseFailure::InvalidParameters],
    'null item' => [['parameters' => ['-sV', null]], ParseFailure::InvalidParameters],
    'bool item' => [['parameters' => [true]], ParseFailure::InvalidParameters],
]);

it('rejects oversized output before decoding', function () {
    $raw = llmJson(['target' => str_repeat('a', CommandParsingService::MAX_OUTPUT_BYTES)]);

    expect(parser()->parse($raw)->failure)->toBe(ParseFailure::OutputTooLarge);
});

it('rejects invalid UTF-8', function () {
    expect(parser()->parse('{"tool":"nm'."\xB1".'ap"}')->failure)->toBe(ParseFailure::InvalidUtf8);
});

it('rejects deeply nested JSON', function () {
    expect(parser()->parse(llmJson(['parameters' => [[[[[[[['x']]]]]]]]]))->failure)
        ->toBe(ParseFailure::InvalidJson);
});

// --------------------------------------------- request ids and messages

it('assigns a fresh backend-compatible request id even on failure', function () {
    $ok = parser()->parse(llmJson());
    $bad = parser()->parse('not json');

    expect($bad->requestId)->toMatch(BACKEND_REQUEST_ID_REGEX)
        ->and($bad->requestId)->not->toBe($ok->requestId);
});

it('generates unique request ids', function () {
    $gen = new RequestIdGenerator;
    $ids = array_map(fn () => $gen->generate(), range(1, 1000));

    expect(array_unique($ids))->toHaveCount(1000);
    foreach ($ids as $id) {
        expect($id)->toMatch(BACKEND_REQUEST_ID_REGEX)->toHaveLength(30);
    }
});

it('never echoes the full LLM output in failure messages', function () {
    $marker = 'UNIQUE-MARKER-'.bin2hex(random_bytes(4));
    $cases = [
        'Here: nmap '.$marker,
        json_encode(['tool' => 'nmap', 'action' => 'a', 'target' => $marker, 'parameters' => [1]]),
        json_encode(['tool' => 'nmap', 'action' => 'a', 'target' => 't', 'parameters' => [], 'extra' => $marker]),
    ];

    foreach ($cases as $raw) {
        $result = parser()->parse($raw);
        expect($result->isSuccess())->toBeFalse()
            ->and(str_contains((string) $result->message(), $marker))->toBeFalse();
    }
});

it('truncates offending key names in messages', function () {
    $longKey = str_repeat('k', 500);
    $data = json_decode(llmJson(), true) + [$longKey => 1];

    expect(strlen(parser()->parse(json_encode($data))->message()))->toBeLessThan(250);
});

it('keeps a bounded, valid UTF-8 excerpt of the raw output for history', function () {
    $raw = str_repeat('é', CommandParsingService::EXCERPT_BYTES); // 2 bytes per char
    $excerpt = CommandParsingService::excerpt($raw);

    expect(mb_check_encoding($excerpt, 'UTF-8'))->toBeTrue()
        ->and($excerpt)->toEndWith('…[truncated]')
        ->and(strlen($excerpt))->toBeLessThanOrEqual(CommandParsingService::EXCERPT_BYTES + strlen('…[truncated]'));

    expect(parser()->parse(llmJson())->rawExcerpt)->toBe(llmJson());
});
