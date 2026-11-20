<?php

use App\Services\Execution\AuthorizedCommand;
use App\Services\Execution\ExecutionGate;
use App\Services\Execution\GateRefusal;
use App\Services\Execution\SignatureCheck;
use App\Services\Execution\SignatureVerifier;
use App\Services\Execution\UnavailableSignatureVerifier;
use App\Services\ScopeEnforcer\Authorization;
use App\Services\ScopeEnforcer\Decision;
use App\Services\ScopeEnforcer\FailureCode;
use App\Services\ScopeEnforcer\ValidationRequest;
use App\Services\ScopeEnforcer\ValidationResult;
use Illuminate\Support\Carbon;

const G_ISSUED = 1_700_000_000;
const G_ID = 'REQ-gate-001';

function gReq(array $o = []): ValidationRequest
{
    $v = array_replace([
        'id' => G_ID, 'tool' => 'nmap', 'action' => 'service_detection',
        'target' => '192.168.100.50', 'parameters' => ['-sV', '-p', '80,443'],
    ], $o);

    return new ValidationRequest($v['id'], $v['tool'], $v['action'], $v['target'], $v['parameters']);
}

function gAuth(array $o = []): Authorization
{
    $v = array_replace([
        'issued_at' => G_ISSUED, 'expires_at' => G_ISSUED + 60,
        'algorithm' => 'HMAC-SHA256', 'signature' => str_repeat('ab', 32),
    ], $o);

    return new Authorization($v['issued_at'], $v['expires_at'], $v['algorithm'], $v['signature']);
}

function gResult(array $o = []): ValidationResult
{
    $v = array_replace([
        'id' => G_ID, 'decision' => Decision::Allow, 'risk' => 'LOW', 'exec' => true,
        'auth' => gAuth(), 'policy' => '1.0',
    ], $o);

    return ValidationResult::fromBackend(
        $v['id'], $v['decision'], $v['risk'], $v['exec'], [],
        ['command_valid' => true, 'target_valid' => true, 'tool_valid' => true, 'parameters_valid' => true],
        $v['policy'], $v['auth'],
    );
}

/** Test-only verifier: returns a fixed answer and records what it was asked to verify. */
function gVerifier(SignatureCheck $answer): SignatureVerifier
{
    return new class($answer) implements SignatureVerifier
    {
        public array $calls = [];

        public function __construct(private SignatureCheck $answer) {}

        public function verify(ValidationRequest $command, ValidationResult $result): SignatureCheck
        {
            $this->calls[] = [$command, $result];

            return $this->answer;
        }
    };
}

function gGate(SignatureVerifier $verifier): ExecutionGate
{
    return new ExecutionGate($verifier, app('config'));
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(G_ISSUED + 10));
});

afterEach(function () {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------- happy path

it('authorizes an authentic, current ALLOW bound to the identical command', function () {
    $verifier = gVerifier(SignatureCheck::Valid);
    $decision = gGate($verifier)->authorize(gReq(), gResult(), gReq());

    expect($decision->isAuthorized())->toBeTrue()
        ->and($decision->refusal)->toBeNull()
        ->and($decision->command)->toBeInstanceOf(AuthorizedCommand::class)
        ->and($decision->command->tool)->toBe('nmap')
        ->and($decision->command->target)->toBe('192.168.100.50')
        ->and($decision->command->parameters)->toBe(['-sV', '-p', '80,443'])
        ->and($decision->command->expiresAt)->toBe(G_ISSUED + 60)
        ->and($decision->command->policyVersion)->toBe('1.0');

    // the signature is verified over the command that will actually run
    expect($verifier->calls)->toHaveCount(1)
        ->and($verifier->calls[0][0]->parameters)->toBe(['-sV', '-p', '80,443']);
});

// ------------------------------------------------- production binding today

it('is bound to the always-unavailable verifier in production until stage 3', function () {
    expect(app(SignatureVerifier::class))->toBeInstanceOf(UnavailableSignatureVerifier::class);

    $decision = app(ExecutionGate::class)->authorize(gReq(), gResult(), gReq());

    expect($decision->isAuthorized())->toBeFalse()
        ->and($decision->refusal)->toBe(GateRefusal::SignatureUnavailable);
});

// ------------------------------------------------------------ decision level

it('refuses anything that is not a backend ALLOW with execution permission', function (Closure $result, GateRefusal $expected) {
    $verifier = gVerifier(SignatureCheck::Valid);
    $decision = gGate($verifier)->authorize(gReq(), $result(), gReq());

    expect($decision->isAuthorized())->toBeFalse()
        ->and($decision->command)->toBeNull()
        ->and($decision->refusal)->toBe($expected)
        ->and($verifier->calls)->toBe([]); // never even reaches signature verification
})->with([
    'backend DENY' => [fn () => gResult(['decision' => Decision::Deny, 'risk' => 'HIGH', 'exec' => false, 'auth' => null]), GateRefusal::NotAllow],
    'backend WARNING' => [fn () => gResult(['decision' => Decision::Warning, 'risk' => 'MEDIUM', 'exec' => false, 'auth' => null]), GateRefusal::NotAllow],
    'WARNING carrying an authorization' => [fn () => gResult(['decision' => Decision::Warning, 'exec' => true]), GateRefusal::NotAllow],
    'fail-closed: unreachable' => [fn () => ValidationResult::failClosed(G_ID, FailureCode::Unreachable), GateRefusal::NotBackendDecision],
    'fail-closed: timeout' => [fn () => ValidationResult::failClosed(G_ID, FailureCode::Timeout), GateRefusal::NotBackendDecision],
    'fail-closed: 401' => [fn () => ValidationResult::failClosed(G_ID, FailureCode::Unauthorized), GateRefusal::NotBackendDecision],
    'ALLOW without execution_allowed' => [fn () => gResult(['exec' => false]), GateRefusal::ExecutionNotAllowed],
    'ALLOW without authorization' => [fn () => gResult(['auth' => null]), GateRefusal::MissingAuthorization],
]);

// -------------------------------------------------------------- binding level

it('refuses when the command differs from the validated one', function (array $change, GateRefusal $expected) {
    $verifier = gVerifier(SignatureCheck::Valid);
    $decision = gGate($verifier)->authorize(gReq(), gResult(), gReq($change));

    expect($decision->refusal)->toBe($expected)
        ->and($verifier->calls)->toBe([]);
})->with([
    'target changed' => [['target' => '192.168.100.51'], GateRefusal::CommandMismatch],
    'target padded' => [['target' => '192.168.100.50 '], GateRefusal::CommandMismatch],
    'tool changed' => [['tool' => 'whois'], GateRefusal::CommandMismatch],
    'tool case changed' => [['tool' => 'NMAP'], GateRefusal::CommandMismatch],
    'action changed' => [['action' => 'os_detection'], GateRefusal::CommandMismatch],
    'parameter added' => [['parameters' => ['-sV', '-p', '80,443', '-A']], GateRefusal::CommandMismatch],
    'parameter removed' => [['parameters' => ['-sV']], GateRefusal::CommandMismatch],
    'port list changed' => [['parameters' => ['-sV', '-p', '1-65535']], GateRefusal::CommandMismatch],
    'parameter order changed' => [['parameters' => ['-p', '80,443', '-sV']], GateRefusal::CommandMismatch],
    'request id changed' => [['id' => 'REQ-other'], GateRefusal::RequestIdMismatch],
]);

it('refuses when the decision belongs to another request', function () {
    $decision = gGate(gVerifier(SignatureCheck::Valid))->authorize(gReq(), gResult(['id' => 'REQ-other']), gReq());

    expect($decision->refusal)->toBe(GateRefusal::RequestIdMismatch);
});

// ----------------------------------------------------------------- time level

it('enforces algorithm, TTL and validity window', function (array $auth, int $nowOffset, GateRefusal $expected) {
    Carbon::setTestNow(Carbon::createFromTimestamp(G_ISSUED + $nowOffset));
    $verifier = gVerifier(SignatureCheck::Valid);

    $decision = gGate($verifier)->authorize(gReq(), gResult(['auth' => gAuth($auth)]), gReq());

    expect($decision->refusal)->toBe($expected)
        ->and($decision->refusal->requiresRevalidation())->toBe($expected === GateRefusal::Expired)
        ->and($verifier->calls)->toBe([]);
})->with([
    'algorithm none' => [['algorithm' => 'none'], 10, GateRefusal::UnsupportedAlgorithm],
    'algorithm SHA1' => [['algorithm' => 'HMAC-SHA1'], 10, GateRefusal::UnsupportedAlgorithm],
    'TTL 61 s' => [['expires_at' => G_ISSUED + 61], 10, GateRefusal::TtlTooLong],
    'TTL 1 day' => [['expires_at' => G_ISSUED + 86400], 10, GateRefusal::TtlTooLong],
    'expires before issue' => [['expires_at' => G_ISSUED - 1], 10, GateRefusal::TtlTooLong],
    'issued 6 s in the future (skew 5)' => [[], -6, GateRefusal::NotYetValid],
    'exactly at expiry' => [[], 60, GateRefusal::Expired],
    'one second after expiry' => [[], 61, GateRefusal::Expired],
    'long after expiry' => [[], 3600, GateRefusal::Expired],
]);

it('accepts the edges of the validity window', function (int $nowOffset) {
    Carbon::setTestNow(Carbon::createFromTimestamp(G_ISSUED + $nowOffset));

    expect(gGate(gVerifier(SignatureCheck::Valid))->authorize(gReq(), gResult(), gReq())->isAuthorized())->toBeTrue();
})->with(['issued 5 s in the future (within skew)' => -5, 'at issue time' => 0, 'one second before expiry' => 59]);

// ------------------------------------------------------------ signature level

it('refuses unless the signature verifier says valid', function (SignatureCheck $answer, GateRefusal $expected) {
    $decision = gGate(gVerifier($answer))->authorize(gReq(), gResult(), gReq());

    expect($decision->isAuthorized())->toBeFalse()->and($decision->refusal)->toBe($expected);
})->with([
    'invalid' => [SignatureCheck::Invalid, GateRefusal::SignatureInvalid],
    'unavailable' => [SignatureCheck::Unavailable, GateRefusal::SignatureUnavailable],
]);

it('fails closed when the verifier throws', function () {
    $throwing = new class implements SignatureVerifier
    {
        public function verify(ValidationRequest $command, ValidationResult $result): SignatureCheck
        {
            throw new RuntimeException('boom');
        }
    };

    expect(gGate($throwing)->authorize(gReq(), gResult(), gReq())->refusal)->toBe(GateRefusal::Unexpected);
});
