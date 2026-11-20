<?php

use App\Models\ActivityLog;
use App\Services\Execution\ProcessOutcome;
use App\Services\Execution\ProcessRunner;
use App\Services\Execution\SignatureCheck;
use App\Services\Execution\SignatureVerifier;
use App\Services\History\HistoryLogService;
use App\Services\History\HistoryWriteException;
use App\Services\History\SecretMasker;
use App\Services\ScopeEnforcer\ValidationRequest;
use App\Services\ScopeEnforcer\ValidationResult;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/*
| Full HTTP flow through POST /recon. Ollama and the Scope Enforcer are faked
| (Http::fake), the ProcessRunner is a Mockery mock: no model, no VM 2, no
| process. Targets are the policy placeholder example only.
*/

const R_NOW = 1_700_000_000;
const R_SE = 'http://enforcer.test';
const R_CMD = '{"tool":"nmap","action":"service_detection","target":"192.168.100.50","parameters":["-sV","-p","80,443"]}';
const R_INSTRUCTION = 'scan services on 192.168.100.50 ports 80 and 443';

function rLlm(string $content): Closure
{
    return fn () => Http::response([
        'model' => 'deepseek-coder:6.7b',
        'message' => ['role' => 'assistant', 'content' => $content],
        'done' => true,
        'done_reason' => 'stop',
    ]);
}

function rBody(string $decision, array $o = []): array
{
    $allow = $decision === 'ALLOW';

    return array_replace([
        'decision' => $decision,
        'risk_level' => ['ALLOW' => 'LOW', 'WARNING' => 'MEDIUM', 'DENY' => 'HIGH'][$decision],
        'execution_allowed' => $allow,
        'reasons' => $allow ? [] : [$decision === 'WARNING' ? 'Timing -T4 requires review' : 'Tool is not allowed by policy'],
        'validation' => ['command_valid' => true, 'target_valid' => true, 'tool_valid' => $decision !== 'DENY', 'parameters_valid' => true],
        'policy_version' => '1.0',
        'authorization' => $allow ? [
            'issued_at' => R_NOW, 'expires_at' => R_NOW + 60, 'algorithm' => 'HMAC-SHA256', 'signature' => str_repeat('9f', 32),
        ] : null,
    ], $o);
}

/** Scope Enforcer fake that echoes the request_id and answers from a sequence. */
function rSe(array ...$bodies): Closure
{
    $i = 0;

    return function (Request $r) use (&$i, $bodies) {
        $body = $bodies[min($i++, count($bodies) - 1)];

        return Http::response(['request_id' => $r->data()['request_id']] + $body);
    };
}

function rFake(Closure $llm, Closure $se): void
{
    Http::fake(['127.0.0.1:11434/*' => $llm, 'enforcer.test/*' => $se]);
}

function rSeRequests(): array
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), R_SE))->map(fn ($pair) => $pair[0])->values()->all();
}

function rValidSignatures(): void
{
    app()->instance(SignatureVerifier::class, new class implements SignatureVerifier
    {
        public function verify(ValidationRequest $command, ValidationResult $result): SignatureCheck
        {
            return SignatureCheck::Valid; // test-only stand-in for the stage-3 HMAC verifier
        }
    });
}

beforeEach(function () {
    signIn();
    Carbon::setTestNow(Carbon::createFromTimestamp(R_NOW));
    Sleep::fake();
    Http::preventStrayRequests();

    $this->dir = sys_get_temp_dir().'/shellseek-recon-test-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0700);
    file_put_contents($this->dir.'/token', 'fake-recon-token-0123456789');
    chmod($this->dir.'/token', 0600);
    config([
        'scope_enforcer.base_url' => R_SE,
        'scope_enforcer.token_file' => $this->dir.'/token',
        'scope_enforcer.signing_secret_file' => $this->dir.'/missing',
        'llm.base_url' => 'http://127.0.0.1:11434',
    ]);

    $this->runner = Mockery::mock(ProcessRunner::class);
    app()->instance(ProcessRunner::class, $this->runner);
});

afterEach(function () {
    Carbon::setTestNow();
    @unlink($this->dir.'/token');
    @rmdir($this->dir);
});

// ------------------------------------------------------------------- pages

it('shows the reconnaissance form', function () {
    $this->get('/recon')->assertOk()->assertSee('name="instruction"', false)->assertSee('Recon Workspace');
});

it('rejects empty or too long instructions before calling anything', function (string $instruction) {
    Http::fake();
    $this->runner->shouldNotReceive('run');

    $this->from('/recon')->post('/recon', ['instruction' => $instruction])
        ->assertRedirect('/recon')
        ->assertSessionHasErrors('instruction');

    Http::assertNothingSent();
    expect(ActivityLog::count())->toBe(0);
})->with(['empty' => '', 'too long' => str_repeat('a', 1001)]);

// ------------------------------------------------------------------- ALLOW

it('does not execute an ALLOW today because signatures cannot be verified yet', function () {
    rFake(rLlm(R_CMD), rSe(rBody('ALLOW')));
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])
        ->assertOk()
        ->assertSee('ALLOW')
        ->assertSee('signature cannot be verified');

    $log = ActivityLog::sole();
    expect($log->decision)->toBe('ALLOW')
        ->and($log->outcome)->toBe('gate_refused')
        ->and($log->gate_refusal)->toBe('signature_unavailable')
        ->and($log->execution_status)->toBeNull();
});

it('executes an authentic ALLOW exactly once with the validated argv', function () {
    rValidSignatures();
    rFake(rLlm(R_CMD), rSe(rBody('ALLOW')));
    $this->runner->shouldReceive('run')->once()
        ->withArgs(fn (array $argv) => $argv === ['/usr/bin/nmap', '-sV', '-p', '80,443', '192.168.100.50'])
        ->andReturn(new ProcessOutcome(0, '80/tcp open http (stub)', '', 321, false, false));

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])
        ->assertOk()
        ->assertSee('Command executed.')
        ->assertSee('80/tcp open http (stub)');

    $log = ActivityLog::sole();
    expect($log->outcome)->toBe('executed')
        ->and($log->stdout)->toBe('80/tcp open http (stub)')
        ->and($log->argv)->toBe(['/usr/bin/nmap', '-sV', '-p', '80,443', '192.168.100.50']);
});

it('sends the LLM command to the Scope Enforcer exactly as generated', function () {
    rFake(rLlm(R_CMD), rSe(rBody('DENY')));
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])->assertOk();

    $sent = rSeRequests();
    expect($sent)->toHaveCount(1);
    $data = $sent[0]->data();
    expect($data['tool'])->toBe('nmap')
        ->and($data['action'])->toBe('service_detection')
        ->and($data['target'])->toBe('192.168.100.50')
        ->and($data['parameters'])->toBe(['-sV', '-p', '80,443'])
        ->and($data['requested_by'])->toBe('laravel-app')
        ->and($data['request_id'])->toStartWith('REQ-')
        ->and($data['request_id'])->toBe(ActivityLog::sole()->request_id);
});

// --------------------------------------------------------- WARNING / DENY

it('holds a WARNING, shows the reasons and never executes', function () {
    rValidSignatures(); // even with a "valid" verifier
    rFake(rLlm(R_CMD), rSe(rBody('WARNING')));
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])
        ->assertOk()
        ->assertSee('WARNING: execution is held')
        ->assertSee('Timing -T4 requires review')
        ->assertSee('MEDIUM');

    expect(ActivityLog::sole()->outcome)->toBe('warning');
});

it('shows DENY reasons and validation flags and never executes', function () {
    rValidSignatures();
    rFake(rLlm(R_CMD), rSe(rBody('DENY')));
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])
        ->assertOk()
        ->assertSee('DENY: the Scope Enforcer rejected this command')
        ->assertSee('Tool is not allowed by policy')
        ->assertSee('tool_valid: false');

    expect(ActivityLog::sole()->outcome)->toBe('denied');
});

it('forwards an unknown tool chosen by the LLM so the backend denies it', function () {
    rFake(rLlm('{"tool":"dig","action":"dns_lookup","target":"example.com","parameters":[]}'), rSe(rBody('DENY')));
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => 'use dig on example.com'])->assertOk()->assertSee('DENY');

    expect(rSeRequests()[0]->data()['tool'])->toBe('dig');
});

// ------------------------------------------------------------ fail-closed

it('treats an unusable Scope Enforcer as DENY and never executes', function (Closure $se, string $code) {
    rValidSignatures();
    rFake(rLlm(R_CMD), $se);
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])
        ->assertOk()
        ->assertSee('Treated as DENY')
        ->assertSee('treated as DENY');

    $log = ActivityLog::sole();
    expect($log->outcome)->toBe('fail_closed')
        ->and($log->decision)->toBe('DENY')
        ->and($log->failure_code)->toBe($code);
})->with([
    'unreachable' => [fn (Request $r) => Create::rejectionFor(new ConnectException('refused', $r->toPsrRequest(), null, ['errno' => 7])), 'unreachable'],
    'timeout' => [fn (Request $r) => Create::rejectionFor(new ConnectException('timeout', $r->toPsrRequest(), null, ['errno' => 28, 'connect_time' => 0.01])), 'timeout'],
    'wrong token (401)' => [fn () => Http::response(['detail' => 'unauthorized'], 401), 'http_401'],
    'server error (500)' => [fn () => Http::response('', 500), 'http_500'],
    'broken JSON' => [fn () => Http::response('{"decision":"ALLOW"', 200), 'invalid_json'],
    'ALLOW without authorization' => [fn (Request $r) => Http::response(['request_id' => $r->data()['request_id']] + rBody('ALLOW', ['authorization' => null])), 'inconsistent_decision'],
]);

// -------------------------------------------------------------------- LLM

it('stops before the Scope Enforcer when the LLM is unavailable', function () {
    Http::fake(['127.0.0.1:11434/*' => fn (Request $r) => Create::rejectionFor(new ConnectException('refused', $r->toPsrRequest(), null, ['errno' => 7]))]);
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])->assertOk()->assertSee('No command was generated');

    expect(rSeRequests())->toBe([])
        ->and(ActivityLog::sole()->outcome)->toBe('llm_failed');
});

it('stops before the Scope Enforcer when the LLM output is not a valid command', function () {
    rFake(rLlm('Sure! nmap -sV 192.168.100.50'), rSe(rBody('ALLOW')));
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])
        ->assertOk()
        ->assertSee('not valid JSON')
        ->assertSee('Sure! nmap -sV 192.168.100.50');

    expect(rSeRequests())->toBe([])
        ->and(ActivityLog::sole()->outcome)->toBe('parse_failed');
});

it('escapes untrusted LLM output in the page', function () {
    rFake(rLlm('{"tool":"nmap","action":"service_detection","target":"<script>alert(1)</script>","parameters":[]}'), rSe(rBody('DENY')));
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

// ---------------------------------------------------------- re-validation

it('re-validates an expired ALLOW once under a new request id and then executes', function () {
    rValidSignatures();
    rFake(rLlm(R_CMD), rSe(
        rBody('ALLOW', ['authorization' => ['issued_at' => R_NOW - 120, 'expires_at' => R_NOW - 60, 'algorithm' => 'HMAC-SHA256', 'signature' => str_repeat('11', 32)]]),
        rBody('ALLOW'),
    ));
    $this->runner->shouldReceive('run')->once()->andReturn(new ProcessOutcome(0, 'ok', '', 1, false, false));

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])->assertOk()->assertSee('validated again');

    $sent = rSeRequests();
    expect($sent)->toHaveCount(2)
        ->and($sent[1]->data()['request_id'])->not->toBe($sent[0]->data()['request_id'])
        ->and(array_diff_key($sent[1]->data(), ['request_id' => 1]))->toBe(array_diff_key($sent[0]->data(), ['request_id' => 1]));

    $log = ActivityLog::sole();
    expect($log->revalidated)->toBeTrue()
        ->and($log->original_request_id)->toBe($sent[0]->data()['request_id'])
        ->and($log->request_id)->toBe($sent[1]->data()['request_id'])
        ->and($log->outcome)->toBe('executed');
});

it('re-validates at most once and refuses if the new authorization is expired too', function () {
    rValidSignatures();
    $expired = rBody('ALLOW', ['authorization' => ['issued_at' => R_NOW - 120, 'expires_at' => R_NOW - 60, 'algorithm' => 'HMAC-SHA256', 'signature' => str_repeat('11', 32)]]);
    rFake(rLlm(R_CMD), rSe($expired, $expired, $expired));
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])->assertOk()->assertSee('has expired');

    expect(rSeRequests())->toHaveCount(2)
        ->and(ActivityLog::sole()->gate_refusal)->toBe('expired');
});

it('stops if the re-validation is no longer ALLOW', function () {
    rValidSignatures();
    rFake(rLlm(R_CMD), rSe(
        rBody('ALLOW', ['authorization' => ['issued_at' => R_NOW - 120, 'expires_at' => R_NOW - 60, 'algorithm' => 'HMAC-SHA256', 'signature' => str_repeat('11', 32)]]),
        rBody('DENY'),
    ));
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])->assertOk()->assertSee('DENY');

    expect(ActivityLog::sole()->outcome)->toBe('denied');
});

// ----------------------------------------------------------------- history

it('does nothing at all when the history cannot be written', function () {
    Schema::drop('activity_logs');
    Http::fake();
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])
        ->assertOk()
        ->assertSee('For safety nothing was executed');

    Http::assertNothingSent();
});

it('never executes when the gate result cannot be recorded', function () {
    rValidSignatures();
    rFake(rLlm(R_CMD), rSe(rBody('ALLOW')));
    $history = Mockery::mock(HistoryLogService::class, [app(SecretMasker::class)])->makePartial();
    $history->shouldReceive('recordGate')->andThrow(new HistoryWriteException('disk full'));
    app()->instance(HistoryLogService::class, $history);
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])
        ->assertOk()
        ->assertSee('For safety nothing was executed');
});

// ---------------------------------------------------------------- throttle

it('rate-limits the reconnaissance endpoint', function () {
    Http::fake(['127.0.0.1:11434/*' => fn () => Http::response('', 500)]);
    $this->runner->shouldNotReceive('run');

    foreach (range(1, 10) as $_) {
        $this->followingRedirects()->post('/recon', ['instruction' => 'scan'])->assertOk();
    }
    $this->followingRedirects()->post('/recon', ['instruction' => 'scan'])->assertStatus(429);
});

// ------------------------------------------------------ post/redirect/get

it('redirects to the stored result so a refresh never repeats the request', function () {
    rFake(rLlm(R_CMD), rSe(rBody('DENY')));
    $this->runner->shouldNotReceive('run');

    $response = $this->post('/recon', ['instruction' => R_INSTRUCTION]);

    $log = ActivityLog::sole();
    $response->assertRedirect(route('recon.show', $log))->assertSessionHas('recon.status', 'denied');
    expect(rSeRequests())->toHaveCount(1);

    // "refresh" = GET of the session page: nothing is sent again, nothing new is recorded
    $this->get(route('recon.show', $log))->assertOk()->assertSee($log->request_id);
    $this->get(route('recon.show', $log))->assertOk();
    $this->get(route('validations.show', $log))->assertOk()->assertSee($log->request_id);
    expect(rSeRequests())->toHaveCount(1)
        ->and(ActivityLog::count())->toBe(1);
});

it('keeps the instruction in the form when nothing could be recorded', function () {
    Schema::drop('activity_logs');
    Http::fake();

    $this->post('/recon', ['instruction' => R_INSTRUCTION])
        ->assertRedirect(route('recon.index'))
        ->assertSessionHasInput('instruction', R_INSTRUCTION)
        ->assertSessionHas('recon.status', 'history_error');
});

it('shows the WARNING hold notice on the result page', function () {
    rFake(rLlm(R_CMD), rSe(rBody('WARNING')));
    $this->runner->shouldNotReceive('run');

    $this->followingRedirects()->post('/recon', ['instruction' => R_INSTRUCTION])
        ->assertOk()
        ->assertSee('Human approval for WARNING is not available in this version');
});
