<?php

use App\Services\Execution\ExecutionGate;
use App\Services\Execution\ExecutionResult;
use App\Services\Execution\ExecutionService;
use App\Services\Execution\ProcessOutcome;
use App\Services\Execution\ProcessRunner;
use App\Services\Execution\SignatureCheck;
use App\Services\Execution\SignatureVerifier;
use App\Services\ScopeEnforcer\Authorization;
use App\Services\ScopeEnforcer\Decision;
use App\Services\ScopeEnforcer\FailureCode;
use App\Services\ScopeEnforcer\ValidationRequest;
use App\Services\ScopeEnforcer\ValidationResult;
use Illuminate\Support\Carbon;

/*
| Gate + ExecutionService wired from the container, with the ProcessRunner
| replaced by a Mockery spy. Proves that DENY, WARNING and every failure
| never reach process execution. No real process is started here.
*/

const P_ID = 'REQ-path-001';
const P_ISSUED = 1_700_000_000;

function pReq(array $o = []): ValidationRequest
{
    return new ValidationRequest(P_ID, $o['tool'] ?? 'nmap', 'service_detection', $o['target'] ?? '192.168.100.50', $o['parameters'] ?? ['-sV', '-p', '80']);
}

function pResult(Decision $d, bool $exec, bool $withAuth): ValidationResult
{
    return ValidationResult::fromBackend(
        P_ID, $d, $d === Decision::Allow ? 'LOW' : ($d === Decision::Warning ? 'MEDIUM' : 'HIGH'), $exec, ['reason'],
        ['command_valid' => true, 'target_valid' => true, 'tool_valid' => true, 'parameters_valid' => true],
        '1.0', $withAuth ? new Authorization(P_ISSUED, P_ISSUED + 60, 'HMAC-SHA256', str_repeat('cd', 32)) : null,
    );
}

/** The same sequence the controller will use (stage 8). */
function pAttempt(ValidationRequest $validated, ValidationResult $result, ValidationRequest $toExecute): ?ExecutionResult
{
    $decision = app(ExecutionGate::class)->authorize($validated, $result, $toExecute);

    return $decision->isAuthorized() ? app(ExecutionService::class)->run($decision->command) : null;
}

function pUseVerifier(SignatureCheck $answer): void
{
    app()->instance(SignatureVerifier::class, new class($answer) implements SignatureVerifier
    {
        public function __construct(private SignatureCheck $answer) {}

        public function verify(ValidationRequest $command, ValidationResult $result): SignatureCheck
        {
            return $this->answer;
        }
    });
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(P_ISSUED + 5));
    $this->runner = Mockery::mock(ProcessRunner::class);
    app()->instance(ProcessRunner::class, $this->runner);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('never executes DENY, WARNING or fail-closed results, even with a "valid" signature', function (Closure $result) {
    pUseVerifier(SignatureCheck::Valid);
    $this->runner->shouldNotReceive('run');

    expect(pAttempt(pReq(), $result(), pReq()))->toBeNull();
})->with([
    'DENY' => fn () => pResult(Decision::Deny, false, false),
    'WARNING' => fn () => pResult(Decision::Warning, false, false),
    'WARNING with forged execution flag + authorization' => fn () => pResult(Decision::Warning, true, true),
    'DENY with forged execution flag + authorization' => fn () => pResult(Decision::Deny, true, true),
    'fail-closed unreachable' => fn () => ValidationResult::failClosed(P_ID, FailureCode::Unreachable),
    'fail-closed timeout' => fn () => ValidationResult::failClosed(P_ID, FailureCode::Timeout),
    'fail-closed invalid json' => fn () => ValidationResult::failClosed(P_ID, FailureCode::InvalidJson),
    'fail-closed 401' => fn () => ValidationResult::failClosed(P_ID, FailureCode::Unauthorized),
    'fail-closed 500' => fn () => ValidationResult::failClosed(P_ID, FailureCode::ServerError),
    'ALLOW without authorization' => fn () => pResult(Decision::Allow, true, false),
    'ALLOW without execution_allowed' => fn () => pResult(Decision::Allow, false, true),
]);

it('never executes an ALLOW whose signature is invalid or unverifiable', function (SignatureCheck $answer) {
    pUseVerifier($answer);
    $this->runner->shouldNotReceive('run');

    expect(pAttempt(pReq(), pResult(Decision::Allow, true, true), pReq()))->toBeNull();
})->with([SignatureCheck::Invalid, SignatureCheck::Unavailable]);

it('never executes an ALLOW today: production verifier is unavailable', function () {
    // no verifier override: real container binding
    $this->runner->shouldNotReceive('run');

    expect(pAttempt(pReq(), pResult(Decision::Allow, true, true), pReq()))->toBeNull();
});

it('never executes a command that was changed after validation', function (array $change) {
    pUseVerifier(SignatureCheck::Valid);
    $this->runner->shouldNotReceive('run');

    expect(pAttempt(pReq(), pResult(Decision::Allow, true, true), pReq($change)))->toBeNull();
})->with([
    'target' => [['target' => '10.0.0.1']],
    'parameters' => [['parameters' => ['-sV', '-p', '80', '-A']]],
    'tool' => [['tool' => 'whois']],
]);

it('never executes an expired ALLOW', function () {
    pUseVerifier(SignatureCheck::Valid);
    Carbon::setTestNow(Carbon::createFromTimestamp(P_ISSUED + 61));
    $this->runner->shouldNotReceive('run');

    expect(pAttempt(pReq(), pResult(Decision::Allow, true, true), pReq()))->toBeNull();
});

it('executes exactly once, with the exact argv, only for an authentic ALLOW', function () {
    pUseVerifier(SignatureCheck::Valid);
    $this->runner->shouldReceive('run')
        ->once()
        ->withArgs(fn (array $argv, float $timeout) => $argv === ['/usr/bin/nmap', '-sV', '-p', '80', '192.168.100.50'] && $timeout === 300.0)
        ->andReturn(new ProcessOutcome(0, 'stub output', '', 12, false, false));

    $result = pAttempt(pReq(), pResult(Decision::Allow, true, true), pReq());

    expect($result)->not->toBeNull()
        ->and($result->stdout)->toBe('stub output')
        ->and($result->exitCode)->toBe(0);
});
