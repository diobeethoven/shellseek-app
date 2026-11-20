<?php

use App\Models\ActivityLog;
use App\Services\Command\CommandParsingService;
use App\Services\Execution\ExecutionResult;
use App\Services\Execution\ExecutionStatus;
use App\Services\Execution\GateDecision;
use App\Services\Execution\GateRefusal;
use App\Services\History\HistoryLogService;
use App\Services\History\HistoryWriteException;
use App\Services\History\Outcome;
use App\Services\History\SecretMasker;
use App\Services\Llm\LlmFailure;
use App\Services\Llm\LlmResult;
use App\Services\ScopeEnforcer\Authorization;
use App\Services\ScopeEnforcer\Decision;
use App\Services\ScopeEnforcer\FailureCode;
use App\Services\ScopeEnforcer\ValidationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
| SQLite in-memory. Secrets are FAKE values in temporary 0600 files that the
| config points to; the real token / signing secret are never used here.
*/

const H_TOKEN = 'fake-api-token-H1st0ry-0123456789';
const H_SIGNING = 'fake-signing-secret-H1st0ry-abcdef';
const H_LLM_JSON = '{"tool":"nmap","action":"service_detection","target":"192.168.100.50","parameters":["-sV","-p","80"]}';

function hService(): HistoryLogService
{
    return app(HistoryLogService::class);
}

function hValidation(Decision $d, bool $exec = false, bool $auth = false, array $reasons = ['r']): ValidationResult
{
    return ValidationResult::fromBackend(
        'PLACEHOLDER', $d, ['ALLOW' => 'LOW', 'WARNING' => 'MEDIUM', 'DENY' => 'HIGH'][$d->value], $exec, $reasons,
        ['command_valid' => true, 'target_valid' => true, 'tool_valid' => true, 'parameters_valid' => $d !== Decision::Deny],
        '1.0', $auth ? new Authorization(1_700_000_000, 1_700_000_060, 'HMAC-SHA256', str_repeat('ef', 32)) : null,
    );
}

/** Every stored column of every row, as one string. */
function hDump(): string
{
    return json_encode(DB::table('activity_logs')->get()->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/shellseek-history-test-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0700);
    foreach (['token' => H_TOKEN, 'signing' => H_SIGNING] as $name => $value) {
        file_put_contents($this->dir.'/'.$name, $value);
        chmod($this->dir.'/'.$name, 0600);
    }
    config([
        'scope_enforcer.token_file' => $this->dir.'/token',
        'scope_enforcer.signing_secret_file' => $this->dir.'/signing',
    ]);
});

afterEach(function () {
    foreach (['token', 'signing'] as $name) {
        @unlink($this->dir.'/'.$name);
    }
    @rmdir($this->dir);
});

// ------------------------------------------------------------- full flows

it('records a full executed flow in a single row', function () {
    $h = hService();
    $log = $h->start('scan services on 192.168.100.50 port 80');
    $h->recordLlm($log, LlmResult::success(H_LLM_JSON, 'deepseek-coder:6.7b', 1234));
    $parsed = app(CommandParsingService::class)->parse(H_LLM_JSON);
    $h->recordParse($log, $parsed);
    $h->recordValidation($log, hValidation(Decision::Allow, true, true, []));
    $h->recordGate($log, GateDecision::refused(GateRefusal::SignatureUnavailable));

    expect(ActivityLog::count())->toBe(1);
    $row = ActivityLog::first();
    expect($row->request_id)->toBe($parsed->requestId)
        ->and($row->instruction)->toBe('scan services on 192.168.100.50 port 80')
        ->and($row->llm_model)->toBe('deepseek-coder:6.7b')
        ->and($row->llm_duration_ms)->toBe(1234)
        ->and($row->tool)->toBe('nmap')
        ->and($row->parameters)->toBe(['-sV', '-p', '80'])
        ->and($row->decision)->toBe('ALLOW')
        ->and($row->execution_allowed)->toBeTrue()
        ->and($row->validation['parameters_valid'])->toBeTrue()
        ->and($row->authorization_expires_at)->toBe(1_700_000_060)
        ->and($row->gate_refusal)->toBe('signature_unavailable')
        ->and($row->stage)->toBe('gate')
        ->and($row->outcome)->toBe(Outcome::GateRefused->value);

    // the HMAC signature itself is not stored
    expect(hDump())->not->toContain(str_repeat('ef', 32));
});

it('records execution results', function (ExecutionStatus $status, Outcome $outcome) {
    $h = hService();
    $log = $h->start('x');
    $h->recordExecution($log, new ExecutionResult('REQ-1', $status, ['/usr/bin/nmap', '-sV', '192.168.100.50'], 0, 'out', 'err', 42, 'why'));

    $row = $log->fresh();
    expect($row->outcome)->toBe($outcome->value)
        ->and($row->stage)->toBe('execute')
        ->and($row->execution_status)->toBe($status->value)
        ->and($row->argv)->toBe(['/usr/bin/nmap', '-sV', '192.168.100.50'])
        ->and($row->stdout)->toBe('out')
        ->and($row->execution_duration_ms)->toBe(42);
})->with([
    [ExecutionStatus::Completed, Outcome::Executed],
    [ExecutionStatus::TimedOut, Outcome::TimedOut],
    [ExecutionStatus::OutputLimitExceeded, Outcome::OutputLimitExceeded],
    [ExecutionStatus::Refused, Outcome::ExecutionRefused],
    [ExecutionStatus::Error, Outcome::ExecutionError],
]);

it('records each kind of early stop with the right outcome', function (Closure $flow, Outcome $outcome, string $stage) {
    $log = $flow(hService());

    expect(ActivityLog::count())->toBe(1)
        ->and($log->fresh()->outcome)->toBe($outcome->value)
        ->and($log->fresh()->stage)->toBe($stage);
})->with([
    'LLM failed' => [function (HistoryLogService $h) {
        $log = $h->start('scan');

        return $h->recordLlm($log, LlmResult::failure(LlmFailure::Timeout, 'deepseek-coder:6.7b', 120000));
    }, Outcome::LlmFailed, 'llm'],
    'parse failed' => [function (HistoryLogService $h) {
        $log = $h->start('scan');
        $h->recordLlm($log, LlmResult::success('not json', 'm', 1));

        return $h->recordParse($log, app(CommandParsingService::class)->parse('not json'));
    }, Outcome::ParseFailed, 'parse'],
    'DENY' => [function (HistoryLogService $h) {
        return $h->recordValidation($h->start('x'), hValidation(Decision::Deny));
    }, Outcome::Denied, 'validate'],
    'WARNING' => [function (HistoryLogService $h) {
        return $h->recordValidation($h->start('x'), hValidation(Decision::Warning));
    }, Outcome::Warning, 'validate'],
    'fail-closed' => [function (HistoryLogService $h) {
        return $h->recordValidation($h->start('x'), ValidationResult::failClosed('REQ-x', FailureCode::Unreachable));
    }, Outcome::FailClosed, 'validate'],
]);

it('stores fail-closed details so the UI can explain "treated as DENY"', function () {
    $h = hService();
    $log = $h->recordValidation($h->start('x'), ValidationResult::failClosed('REQ-x', FailureCode::Timeout));

    $row = $log->fresh();
    expect($row->decision)->toBe('DENY')
        ->and($row->origin)->toBe('fail_closed')
        ->and($row->failure_code)->toBe('timeout')
        ->and($row->reasons[0])->toContain('Treated as DENY')
        ->and($row->risk_level)->toBeNull();
});

// ---------------------------------------------------------------- masking

it('masks real secrets wherever they appear before writing', function () {
    $h = hService();
    $log = $h->start('use token '.H_TOKEN.' please');
    $llmOut = '{"tool":"nmap","action":"service_detection","target":"'.H_TOKEN.'","parameters":["'.H_SIGNING.'"]}';
    $h->recordLlm($log, LlmResult::success($llmOut, 'm', 1));
    $h->recordParse($log, app(CommandParsingService::class)->parse($llmOut));
    $h->recordValidation($log, hValidation(Decision::Deny, reasons: ['bad target '.H_TOKEN]));
    $h->recordExecution($log, new ExecutionResult('REQ-1', ExecutionStatus::Completed, ['/usr/bin/x', H_SIGNING], 0, 'leak '.H_TOKEN, 'leak '.H_SIGNING, 1, 'reason '.H_TOKEN));

    $dump = hDump();
    expect(str_contains($dump, H_TOKEN))->toBeFalse('token stored in history')
        ->and(str_contains($dump, H_SIGNING))->toBeFalse('signing secret stored in history')
        ->and($dump)->toContain('use token ******** please')
        ->and($log->fresh()->parameters)->toBe(['********'])
        ->and($log->fresh()->argv)->toBe(['/usr/bin/x', '********']);
});

it('masks bearer tokens and authorization headers even if the secret file is missing', function () {
    unlink($this->dir.'/token');
    unlink($this->dir.'/signing');

    $h = hService();
    $log = $h->start("curl -H 'Authorization: Bearer abc.DEF-123_xyz'\nthen use bearer QWERTY987654 now");

    $stored = $log->fresh()->instruction;
    expect($stored)->not->toContain('abc.DEF-123_xyz')
        ->and($stored)->not->toContain('QWERTY987654')
        ->and($stored)->toContain('Authorization: ********')
        ->and($stored)->toContain('Bearer ********');
});

it('does not mask ordinary scan data', function () {
    $masker = app(SecretMasker::class);

    expect($masker->mask('nmap -sV -p 80,443 192.168.100.50'))->toBe('nmap -sV -p 80,443 192.168.100.50')
        ->and($masker->mask('Authorization expired; validate again'))->toBe('Authorization expired; validate again')
        ->and($masker->maskArray(['a' => ['b' => 'x '.H_TOKEN], 1, true]))->toBe(['a' => ['b' => 'x ********'], 1, true]);
});

// ------------------------------------------------------------- truncation

it('truncates long output to a bounded, valid UTF-8 prefix', function () {
    $h = hService();
    $log = $h->start('x');
    $h->recordExecution($log, new ExecutionResult('REQ-1', ExecutionStatus::Completed, [], 0, str_repeat('é', 40000), '', 1));

    $stdout = $log->fresh()->stdout;
    expect(mb_check_encoding($stdout, 'UTF-8'))->toBeTrue()
        ->and($stdout)->toEndWith('…[truncated]')
        ->and(strlen($stdout))->toBeLessThanOrEqual(HistoryLogService::MAX_OUTPUT_BYTES + strlen('…[truncated]'));
});

it('masks before truncating so a secret on the cut boundary cannot leak partially', function () {
    $h = hService();
    $log = $h->start('x');
    $prefix = str_repeat('a', HistoryLogService::MAX_OUTPUT_BYTES - 10);
    $h->recordExecution($log, new ExecutionResult('REQ-1', ExecutionStatus::Completed, [], 0, $prefix.H_TOKEN, '', 1));

    expect(str_contains($log->fresh()->stdout, substr(H_TOKEN, 0, 10)))->toBeFalse('partial token stored');
});

// ---------------------------------------------------------------- failure

it('raises HistoryWriteException when the database cannot be written', function () {
    Schema::drop('activity_logs');

    expect(fn () => hService()->start('scan'))->toThrow(HistoryWriteException::class);
});

it('raises HistoryWriteException on update failures too', function () {
    $h = hService();
    $log = $h->start('scan');
    Schema::drop('activity_logs');

    expect(fn () => $h->recordValidation($log, hValidation(Decision::Allow, true, true)))
        ->toThrow(HistoryWriteException::class);
});
