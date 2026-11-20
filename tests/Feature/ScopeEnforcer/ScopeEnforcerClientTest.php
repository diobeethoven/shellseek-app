<?php

use App\Services\ScopeEnforcer\Decision;
use App\Services\ScopeEnforcer\FailureCode;
use App\Services\ScopeEnforcer\ScopeEnforcerClient;
use App\Services\ScopeEnforcer\ValidationRequest;
use App\Services\ScopeEnforcer\ValidationResult;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
| All Scope Enforcer traffic here is faked with Http::fake. The token is a
| FAKE value in a temporary 0600 file; the real token is never used.
| Target 192.168.100.50 is the policy's placeholder example, never scanned.
*/

const SE_FAKE_TOKEN = 'fake-token-for-tests-0123456789abcdef';
const SE_BASE = 'http://enforcer.test';
const SE_REQ_ID = 'REQ-test-001';

function seRequest(array $overrides = []): ValidationRequest
{
    $v = array_merge([
        'id' => SE_REQ_ID,
        'tool' => 'nmap',
        'action' => 'service_detection',
        'target' => '192.168.100.50',
        'parameters' => ['-sV', '-p', '80,443'],
    ], $overrides);

    return new ValidationRequest($v['id'], $v['tool'], $v['action'], $v['target'], $v['parameters']);
}

function seAllowBody(array $overrides = []): array
{
    return array_replace([
        'request_id' => SE_REQ_ID,
        'decision' => 'ALLOW',
        'risk_level' => 'LOW',
        'execution_allowed' => true,
        'reasons' => [],
        'validation' => ['command_valid' => true, 'target_valid' => true, 'tool_valid' => true, 'parameters_valid' => true],
        'policy_version' => '1.0',
        'authorization' => [
            'issued_at' => 1_700_000_000,
            'expires_at' => 1_700_000_060,
            'algorithm' => 'HMAC-SHA256',
            'signature' => str_repeat('ab', 32),
        ],
    ], $overrides);
}

function seDenyBody(array $overrides = []): array
{
    return array_replace(seAllowBody(), [
        'decision' => 'DENY',
        'risk_level' => 'HIGH',
        'execution_allowed' => false,
        'reasons' => ['Parameter -A is forbidden'],
        'validation' => ['command_valid' => true, 'target_valid' => true, 'tool_valid' => true, 'parameters_valid' => false],
        'authorization' => null,
    ], $overrides);
}

/** Fake a curl-level connection failure carrying the same context Guzzle provides. */
function seConnectFailure(?int $errno, float $connectTime = 0.0): Closure
{
    return function (Request $request) use ($errno, $connectTime) {
        $context = $errno === null ? [] : ['errno' => $errno, 'connect_time' => $connectTime];

        return Create::rejectionFor(new ConnectException(
            "cURL error {$errno}: simulated", $request->toPsrRequest(), null, $context
        ));
    };
}

function seClient(): ScopeEnforcerClient
{
    return app(ScopeEnforcerClient::class);
}

function expectFailClosed(ValidationResult $result, FailureCode $code): void
{
    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->executionAllowed)->toBeFalse()
        ->and($result->authorization)->toBeNull()
        ->and($result->isFailClosed())->toBeTrue()
        ->and($result->isAllowCandidate())->toBeFalse()
        ->and($result->failureCode)->toBe($code)
        ->and($result->reasons[0])->toContain('Treated as DENY');
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/shellseek-client-test-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0700);
    $this->tokenFile = $this->dir.'/token';
    file_put_contents($this->tokenFile, SE_FAKE_TOKEN."\n");
    chmod($this->tokenFile, 0600);

    config([
        'scope_enforcer.base_url' => SE_BASE,
        'scope_enforcer.token_file' => $this->tokenFile,
        'scope_enforcer.connect_timeout' => 1,
        'scope_enforcer.timeout' => 2,
    ]);

    Http::preventStrayRequests();
    Sleep::fake();
});

afterEach(function () {
    @chmod($this->tokenFile, 0600);
    @unlink($this->tokenFile);
    @rmdir($this->dir);
});

// ---------------------------------------------------------------- request

it('sends the exact contract payload with bearer token, JSON headers and fixed requested_by', function () {
    Http::fake([SE_BASE.'/*' => Http::response(seAllowBody())]);

    seClient()->validate(seRequest());

    Http::assertSentCount(1);
    Http::assertSent(function (Request $r) {
        return $r->method() === 'POST'
            && $r->url() === SE_BASE.'/api/v1/validate'
            && $r->header('Authorization') === ['Bearer '.SE_FAKE_TOKEN]
            && $r->header('Content-Type') === ['application/json']
            && $r->header('Accept') === ['application/json']
            && $r->body() === '{"request_id":"REQ-test-001","tool":"nmap","action":"service_detection",'
                .'"target":"192.168.100.50","parameters":["-sV","-p","80,443"],"requested_by":"laravel-app"}';
    });
});

it('forwards unknown tools and suspicious parameters unchanged for the backend to judge', function () {
    Http::fake([SE_BASE.'/*' => Http::response(seDenyBody(['reasons' => ['Tool dig is not allowed']]))]);

    $result = seClient()->validate(seRequest(['tool' => 'dig', 'parameters' => ['-sV;id', '-p80', '$(x)']]));

    Http::assertSent(fn (Request $r) => $r->data()['tool'] === 'dig'
        && $r->data()['parameters'] === ['-sV;id', '-p80', '$(x)']);
    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->isFailClosed())->toBeFalse()
        ->and($result->reasons)->toBe(['Tool dig is not allowed']);
});

// ------------------------------------------------------- backend decisions

it('maps a well-formed ALLOW into an allow candidate (signature not checked here)', function () {
    Http::fake([SE_BASE.'/*' => Http::response(seAllowBody())]);

    $result = seClient()->validate(seRequest());

    expect($result->decision)->toBe(Decision::Allow)
        ->and($result->origin)->toBe(ValidationResult::ORIGIN_BACKEND)
        ->and($result->isAllowCandidate())->toBeTrue()
        ->and($result->riskLevel)->toBe('LOW')
        ->and($result->policyVersion)->toBe('1.0')
        ->and($result->validation)->toBe(seAllowBody()['validation'])
        ->and($result->authorization->toArray())->toBe(seAllowBody()['authorization']);
});

it('passes a WARNING through as held, never as an allow candidate', function () {
    Http::fake([SE_BASE.'/*' => Http::response(seDenyBody([
        'decision' => 'WARNING', 'risk_level' => 'MEDIUM', 'reasons' => ['-T4 requires review'],
    ]))]);

    $result = seClient()->validate(seRequest());

    expect($result->decision)->toBe(Decision::Warning)
        ->and($result->executionAllowed)->toBeFalse()
        ->and($result->isAllowCandidate())->toBeFalse()
        ->and($result->isFailClosed())->toBeFalse()
        ->and($result->reasons)->toBe(['-T4 requires review']);
});

it('passes a DENY through with backend reasons and validation flags', function () {
    Http::fake([SE_BASE.'/*' => Http::response(seDenyBody())]);

    $result = seClient()->validate(seRequest());

    expect($result->decision)->toBe(Decision::Deny)
        ->and($result->isFailClosed())->toBeFalse()
        ->and($result->riskLevel)->toBe('HIGH')
        ->and($result->reasons)->toBe(['Parameter -A is forbidden'])
        ->and($result->validation['parameters_valid'])->toBeFalse();
});

it('accepts a DENY whose authorization field is absent', function () {
    $body = seDenyBody();
    unset($body['authorization']);
    Http::fake([SE_BASE.'/*' => Http::response($body)]);

    expect(seClient()->validate(seRequest())->isFailClosed())->toBeFalse();
});

it('ignores unknown extra response fields', function () {
    Http::fake([SE_BASE.'/*' => Http::response(seAllowBody(['matched_rule' => 'x', 'extra' => [1]]))]);

    expect(seClient()->validate(seRequest())->isAllowCandidate())->toBeTrue();
});

// ------------------------------------------------------------ HTTP status

it('fails closed on any status other than 200', function (int $status, FailureCode $code) {
    Http::fake([SE_BASE.'/*' => Http::response(seAllowBody(), $status, $status === 302 ? ['Location' => 'http://evil.test/'] : [])]);

    expectFailClosed(seClient()->validate(seRequest()), $code);
    Http::assertSentCount(1); // no retry, no redirect follow
})->with([
    '401' => [401, FailureCode::Unauthorized],
    '413' => [413, FailureCode::PayloadTooLarge],
    '500' => [500, FailureCode::ServerError],
    '503' => [503, FailureCode::UnexpectedStatus],
    '422' => [422, FailureCode::UnexpectedStatus],
    '404' => [404, FailureCode::UnexpectedStatus],
    '302 redirect' => [302, FailureCode::UnexpectedStatus],
    '201 with ALLOW body' => [201, FailureCode::UnexpectedStatus],
    '204' => [204, FailureCode::UnexpectedStatus],
]);

// ------------------------------------------------------- malformed bodies

it('fails closed on a body that is not JSON', function (string $body) {
    Http::fake([SE_BASE.'/*' => Http::response($body, 200)]);

    expectFailClosed(seClient()->validate(seRequest()), FailureCode::InvalidJson);
})->with([
    'empty' => '',
    'text' => 'ALLOW',
    'html' => '<html>ok</html>',
    'truncated' => '{"decision":"ALLOW","execution_allowed":true',
    'trailing garbage' => json_encode(seAllowBody()).'x',
]);

it('fails closed on JSON that is not an object', function (string $body) {
    Http::fake([SE_BASE.'/*' => Http::response($body, 200)]);

    expectFailClosed(seClient()->validate(seRequest()), FailureCode::InvalidSchema);
})->with([
    'null' => 'null',
    'true' => 'true',
    'string' => '"ALLOW"',
    'number' => '1',
    'empty array' => '[]',
    'empty object' => '{}',
    'list' => '[{"decision":"ALLOW"}]',
]);

it('fails closed on schema violations', function (Closure $mutate) {
    Http::fake([SE_BASE.'/*' => Http::response($mutate(seAllowBody()))]);

    expectFailClosed(seClient()->validate(seRequest()), FailureCode::InvalidSchema);
})->with([
    'missing request_id' => function ($b) { unset($b['request_id']); return $b; },
    'missing decision' => function ($b) { unset($b['decision']); return $b; },
    'missing risk_level' => function ($b) { unset($b['risk_level']); return $b; },
    'missing execution_allowed' => function ($b) { unset($b['execution_allowed']); return $b; },
    'missing reasons' => function ($b) { unset($b['reasons']); return $b; },
    'missing validation' => function ($b) { unset($b['validation']); return $b; },
    'missing policy_version' => function ($b) { unset($b['policy_version']); return $b; },
    'request_id not string' => fn ($b) => array_replace($b, ['request_id' => 1]),
    'decision lowercase' => fn ($b) => array_replace($b, ['decision' => 'allow']),
    'decision unknown' => fn ($b) => array_replace($b, ['decision' => 'MAYBE']),
    'decision not string' => fn ($b) => array_replace($b, ['decision' => true]),
    'risk_level unknown' => fn ($b) => array_replace($b, ['risk_level' => 'CRITICAL']),
    'execution_allowed string' => fn ($b) => array_replace($b, ['execution_allowed' => 'true']),
    'execution_allowed int' => fn ($b) => array_replace($b, ['execution_allowed' => 1]),
    'reasons string' => fn ($b) => array_replace($b, ['reasons' => 'none']),
    'reasons non-string item' => fn ($b) => array_replace($b, ['reasons' => [1]]),
    'reasons object' => fn ($b) => array_replace($b, ['reasons' => ['a' => 'b']]),
    'validation list' => fn ($b) => array_replace($b, ['validation' => [true, true, true, true]]),
    'validation missing key' => function ($b) { unset($b['validation']['tool_valid']); return $b; },
    'validation non-bool' => function ($b) { $b['validation']['target_valid'] = 'true'; return $b; },
    'policy_version empty' => fn ($b) => array_replace($b, ['policy_version' => '']),
    'policy_version number' => fn ($b) => array_replace($b, ['policy_version' => 1.0]),
    'authorization string' => fn ($b) => array_replace($b, ['authorization' => 'signed']),
    'issued_at string' => function ($b) { $b['authorization']['issued_at'] = '1700000000'; return $b; },
    'issued_at float' => function ($b) { $b['authorization']['issued_at'] = 1700000000.5; return $b; },
    'expires_at missing' => function ($b) { unset($b['authorization']['expires_at']); return $b; },
    'signature missing' => function ($b) { unset($b['authorization']['signature']); return $b; },
    'algorithm HMAC-SHA1' => function ($b) { $b['authorization']['algorithm'] = 'HMAC-SHA1'; return $b; },
    'algorithm none' => function ($b) { $b['authorization']['algorithm'] = 'none'; return $b; },
    'signature uppercase hex' => function ($b) { $b['authorization']['signature'] = str_repeat('AB', 32); return $b; },
    'signature too short' => function ($b) { $b['authorization']['signature'] = str_repeat('ab', 31); return $b; },
    'signature non-hex' => function ($b) { $b['authorization']['signature'] = str_repeat('zz', 32); return $b; },
    'signature with newline' => function ($b) { $b['authorization']['signature'] = str_repeat('ab', 32)."\n"; return $b; },
    'expires_at equals issued_at' => function ($b) { $b['authorization']['expires_at'] = $b['authorization']['issued_at']; return $b; },
    'expires_at before issued_at' => function ($b) { $b['authorization']['expires_at'] = 1; return $b; },
]);

it('fails closed when the response belongs to another request', function () {
    Http::fake([SE_BASE.'/*' => Http::response(seAllowBody(['request_id' => 'REQ-other']))]);

    expectFailClosed(seClient()->validate(seRequest()), FailureCode::RequestIdMismatch);
});

it('fails closed on decisions inconsistent with execution_allowed/authorization', function (array $body) {
    Http::fake([SE_BASE.'/*' => Http::response($body)]);

    expectFailClosed(seClient()->validate(seRequest()), FailureCode::InconsistentDecision);
})->with([
    'ALLOW with execution_allowed false' => [seAllowBody(['execution_allowed' => false])],
    'ALLOW with authorization null' => [seAllowBody(['authorization' => null])],
    'ALLOW without authorization key' => [array_diff_key(seAllowBody(), ['authorization' => 1])],
    'WARNING with execution_allowed true' => [seDenyBody(['decision' => 'WARNING', 'risk_level' => 'MEDIUM', 'execution_allowed' => true])],
    'DENY with execution_allowed true' => [seDenyBody(['execution_allowed' => true])],
    'WARNING with authorization' => [seDenyBody(['decision' => 'WARNING', 'risk_level' => 'MEDIUM', 'authorization' => seAllowBody()['authorization']])],
    'DENY with authorization' => [seDenyBody(['authorization' => seAllowBody()['authorization']])],
]);

// ------------------------------------------------- local refusals (no send)

it('fails closed without sending anything when the token file is missing', function () {
    unlink($this->tokenFile);
    Http::fake();

    expectFailClosed(seClient()->validate(seRequest()), FailureCode::TokenUnavailable);
    Http::assertNothingSent();
});

it('fails closed without sending anything when the token file is world-readable', function () {
    chmod($this->tokenFile, 0644);
    Http::fake();

    expectFailClosed(seClient()->validate(seRequest()), FailureCode::TokenUnavailable);
    Http::assertNothingSent();
});

it('refuses to send a body larger than 16 KB', function () {
    Http::fake();

    expectFailClosed(seClient()->validate(seRequest(['target' => str_repeat('a', 17000)])), FailureCode::RequestTooLarge);
    Http::assertNothingSent();
});

it('refuses to send a command that cannot be JSON-encoded', function () {
    Http::fake();

    expectFailClosed(seClient()->validate(seRequest(['parameters' => ["\xB1\x31"]])), FailureCode::InvalidRequestEncoding);
    Http::assertNothingSent();
});

// ----------------------------------------------------------------- retry

it('retries once after connection refused and then uses the backend answer', function () {
    $calls = 0;
    Http::fake([SE_BASE.'/*' => function (Request $r) use (&$calls) {
        return ++$calls === 1 ? seConnectFailure(7)($r) : Http::response(seAllowBody());
    }]);

    $result = seClient()->validate(seRequest());

    expect($calls)->toBe(2)->and($result->isAllowCandidate())->toBeTrue();
    Sleep::assertSequence([Sleep::for(250)->milliseconds()]);
});

it('gives up after two pre-connection failures', function (int $errno) {
    $calls = 0;
    Http::fake([SE_BASE.'/*' => function (Request $r) use (&$calls, $errno) {
        $calls++;

        return seConnectFailure($errno, 0.0)($r);
    }]);

    expectFailClosed(seClient()->validate(seRequest()), FailureCode::Unreachable);
    expect($calls)->toBe(ScopeEnforcerClient::MAX_ATTEMPTS);
})->with([
    'DNS failure (6)' => 6,
    'connection refused (7)' => 7,
    'connect timeout (28, never connected)' => 28,
]);

it('does not retry a read timeout after the connection was established', function () {
    $calls = 0;
    Http::fake([SE_BASE.'/*' => function (Request $r) use (&$calls) {
        $calls++;

        return seConnectFailure(28, 0.004)($r);
    }]);

    expectFailClosed(seClient()->validate(seRequest()), FailureCode::Timeout);
    expect($calls)->toBe(1);
    Sleep::assertNeverSlept();
});

it('does not retry ambiguous connection failures', function (?int $errno) {
    $calls = 0;
    Http::fake([SE_BASE.'/*' => function (Request $r) use (&$calls, $errno) {
        $calls++;

        return seConnectFailure($errno, 0.004)($r);
    }]);

    expectFailClosed(seClient()->validate(seRequest()), FailureCode::Unreachable);
    expect($calls)->toBe(1);
})->with([
    'empty reply (52)' => 52,
    'SSL connect error (35)' => 35,
    'no curl context' => null,
]);

it('does not retry HTTP errors', function () {
    Http::fake([SE_BASE.'/*' => Http::response('', 500)]);

    seClient()->validate(seRequest());

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('never throws, even on unexpected exceptions', function () {
    Http::fake([SE_BASE.'/*' => fn () => throw new RuntimeException('boom')]);

    expectFailClosed(seClient()->validate(seRequest()), FailureCode::Unexpected);
});

// ---------------------------------------------------------------- logging

it('logs fail-closed events without ever logging the token', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context);
    });

    $responses = [
        Http::response('', 401),
        Http::response('garbage', 200),
        Http::response(seAllowBody(['authorization' => null])),
        Http::response(seAllowBody()),
    ];
    $i = 0;
    Http::fake([SE_BASE.'/*' => function () use (&$i, $responses) { return $responses[$i++]; }]);

    foreach ($responses as $_) {
        seClient()->validate(seRequest());
    }
    chmod($this->tokenFile, 0644);
    seClient()->validate(seRequest());

    $all = implode("\n", $logged);
    expect(str_contains($all, SE_FAKE_TOKEN))->toBeFalse('token leaked into logs')
        ->and(str_contains($all, 'Bearer'))->toBeFalse('authorization header leaked into logs')
        ->and($all)->toContain('scope_enforcer.fail_closed')
        ->and($all)->toContain('"code":"http_401"')
        ->and($all)->toContain('"code":"invalid_json"')
        ->and($all)->toContain('"code":"token_unavailable"');
});

// ----------------------------------------------------------------- health

it('reports healthy on 200 {"status":"ok"} without sending the token', function () {
    Http::fake([SE_BASE.'/health' => Http::response(['status' => 'ok'])]);

    $health = seClient()->health();

    expect($health->reachable)->toBeTrue()->and($health->healthy)->toBeTrue()
        ->and($health->httpStatus)->toBe(200)->and($health->status)->toBe('ok');
    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && $r->url() === SE_BASE.'/health'
        && ! $r->hasHeader('Authorization'));
});

it('reports unhealthy on 503 {"status":"unavailable"}', function () {
    Http::fake([SE_BASE.'/health' => Http::response(['status' => 'unavailable'], 503)]);

    $health = seClient()->health();

    expect($health->reachable)->toBeTrue()->and($health->healthy)->toBeFalse()
        ->and($health->httpStatus)->toBe(503)->and($health->status)->toBe('unavailable')
        ->and($health->message)->toContain('treated as DENY');
});

it('reports unhealthy on a 200 with an unexpected body', function () {
    Http::fake([SE_BASE.'/health' => Http::response('ok', 200)]);

    expect(seClient()->health()->healthy)->toBeFalse();
});

it('reports unreachable when the connection fails', function () {
    Http::fake([SE_BASE.'/health' => seConnectFailure(7)]);

    $health = seClient()->health();

    expect($health->reachable)->toBeFalse()->and($health->healthy)->toBeFalse()
        ->and($health->message)->toContain('Treated as DENY');
});
