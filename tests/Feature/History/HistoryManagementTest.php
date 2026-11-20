<?php

use App\Models\ActivityLog;
use App\Services\Execution\ProcessRunner;
use App\Services\History\HistoryLogService;
use App\Services\History\HistoryWriteException;
use App\Services\History\Outcome;
use App\Services\ScopeEnforcer\FailureCode;
use App\Services\ScopeEnforcer\ValidationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
| Manual Archive / Restore / Delete on the Log page, and that the Dashboard,
| Log and Validation Results stay in sync with it. No network (health faked),
| no secrets (token file points to a missing temp path), nothing executed.
*/

function hmRow(array $o = []): ActivityLog
{
    return ownedBy(ActivityLog::create(array_replace([
        'instruction' => 'hm scan services on 192.168.100.50 port 80',
        'stage' => 'validate',
        'outcome' => 'denied',
        'request_id' => 'REQ-'.bin2hex(random_bytes(6)),
        'tool' => 'nmap',
        'action' => 'service_detection',
        'target' => '192.168.100.50',
        'parameters' => ['-sV', '-p', '80'],
        'decision' => 'DENY',
        'risk_level' => 'HIGH',
        'execution_allowed' => false,
        'reasons' => ['Parameter -A is forbidden'],
        'validation' => ['command_valid' => true, 'target_valid' => true, 'tool_valid' => true, 'parameters_valid' => false],
        'policy_version' => '1.0',
        'origin' => 'backend',
    ], $o)));
}

function hmLogList(string $html): string
{
    preg_match('#<ol class="log" id="log">(.*?)</ol>#s', $html, $m);

    return $m[1] ?? '';
}

beforeEach(function () {
    signIn();
    config([
        'history.management_enabled' => true,
        'history.stale_after_seconds' => 900,
        'scope_enforcer.base_url' => 'http://enforcer.test',
        'scope_enforcer.token_file' => sys_get_temp_dir().'/shellseek-hm-missing-'.bin2hex(random_bytes(6)),
        'scope_enforcer.signing_secret_file' => sys_get_temp_dir().'/shellseek-hm-missing-signing',
        'llm.base_url' => 'http://127.0.0.1:11434',
    ]);
    Http::fake([
        'enforcer.test/health' => Http::response(['status' => 'ok'], 200),
        '127.0.0.1:11434/api/tags' => Http::response(['models' => [['name' => 'deepseek-coder:6.7b']]], 200),
    ]);
    // Management must never reach execution.
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(ProcessRunner::class, $runner);
});

it('shows Archive and Delete for finished entries on the Log page only', function () {
    $log = hmRow();

    $this->get('/history')->assertOk()
        ->assertSee('action="'.route('history.archive', $log).'"', false)
        ->assertSee('action="'.route('history.destroy', $log).'"', false)
        ->assertSee('name="_method" value="DELETE"', false)
        ->assertSee('data-confirm=', false)
        ->assertSee('Archived (0)');

    // the dashboard reuses the entry partial but without management buttons
    $this->get('/')->assertOk()->assertDontSee(route('history.archive', $log));
});

it('archives an entry: hidden from Log, Dashboard and Validation Results, listed under Archived', function () {
    $keep = hmRow(['instruction' => 'hm kept row', 'decision' => 'ALLOW', 'risk_level' => 'LOW', 'outcome' => 'executed',
        'execution_status' => 'completed', 'exit_code' => 0, 'reasons' => []]);
    $gone = hmRow(['instruction' => 'hm archived row']);

    $this->get('/')->assertSee('data-stat="total">2<', false)->assertSee('data-stat="deny">1<', false);

    $this->from('/history')->post(route('history.archive', $gone))
        ->assertRedirect('/history')
        ->assertSessionHas('history.status', 'archived');

    expect($gone->fresh()->archived_at)->not->toBeNull();

    $this->get('/')->assertOk()
        ->assertSee('data-stat="total">1<', false)
        ->assertSee('data-stat="deny">0<', false)
        ->assertSee('data-stat="allow">1<', false)
        ->assertDontSee('hm archived row');

    expect(hmLogList($this->get('/history')->assertSee('Archived (1)')->getContent()))
        ->toContain('hm kept row')->not->toContain('hm archived row');
    expect($this->get('/validations')->getContent())->not->toContain('hm archived row');

    expect(hmLogList($this->get('/history?view=archived')->assertOk()->getContent()))
        ->toContain('hm archived row')->not->toContain('hm kept row')
        ->toContain('action="'.route('history.restore', $gone).'"');

    // the stored session page is still reachable by its link
    $this->get(route('recon.show', $gone))->assertOk();
});

it('restores an archived entry and the dashboard counts it again', function () {
    $log = hmRow(['archived_at' => now()]);
    $this->get('/')->assertSee('data-stat="total">0<', false);

    $this->from('/history?view=archived')->post(route('history.restore', $log))
        ->assertRedirect('/history?view=archived')
        ->assertSessionHas('history.status', 'restored');

    expect($log->fresh()->archived_at)->toBeNull();
    $this->get('/')->assertSee('data-stat="total">1<', false)->assertSee('data-stat="deny">1<', false);
});

it('deletes an entry permanently and the dashboard drops it', function (bool $archivedFirst) {
    hmRow(['instruction' => 'hm other row']);
    $log = hmRow(['instruction' => 'hm deleted row', 'archived_at' => $archivedFirst ? now() : null]);

    $this->from('/history')->delete(route('history.destroy', $log))
        ->assertRedirect('/history')
        ->assertSessionHas('history.status', 'deleted');
    $this->get('/history')->assertSee('Entry #'.$log->id.' deleted permanently.');

    expect(ActivityLog::find($log->id))->toBeNull()
        ->and(ActivityLog::count())->toBe(1);
    $this->get('/')->assertSee('data-stat="total">1<', false)->assertDontSee('hm deleted row');
    $this->get(route('recon.show', $log->id))->assertNotFound();
})->with(['active entry' => false, 'archived entry' => true]);

it('locks entries of a request that is still running', function (string $outcome) {
    $log = hmRow(['outcome' => $outcome]);

    $this->get('/history')->assertSee('running – locked')->assertDontSee(route('history.destroy', $log));
    $this->post(route('history.archive', $log))->assertStatus(409);
    $this->delete(route('history.destroy', $log))->assertStatus(409);

    expect($log->fresh())->not->toBeNull()->archived_at->toBeNull();
})->with([Outcome::InProgress->value, Outcome::Allowed->value, Outcome::Authorized->value]);

it('unlocks a crashed run once it is stale', function () {
    $log = hmRow(['outcome' => Outcome::InProgress->value]);
    $this->travel(901)->seconds();

    $this->delete(route('history.destroy', $log))->assertRedirect();
    expect(ActivityLog::find($log->id))->toBeNull();
});

it('is read-only when history management is disabled', function () {
    config(['history.management_enabled' => false]);
    $log = hmRow();

    $this->get('/history')->assertOk()->assertDontSee(route('history.destroy', $log))->assertDontSee('>Archive<', false);
    $this->get('/settings')->assertOk()->assertSee('Disabled (read-only)');
    $this->post(route('history.archive', $log))->assertForbidden();
    $this->post(route('history.restore', $log))->assertForbidden();
    $this->delete(route('history.destroy', $log))->assertForbidden();

    expect($log->fresh())->not->toBeNull()->archived_at->toBeNull();
});

it('sends a CSRF token with every form and accepts only the right HTTP method', function () {
    // (Laravel skips the CSRF check while running tests; the middleware itself
    // is global for the web group, so here we check the forms carry the token.)
    $log = hmRow();
    $html = hmLogList($this->get('/history')->getContent());
    expect(substr_count($html, 'name="_token"'))->toBe(2);

    $this->get('/history/'.$log->id.'/archive')->assertStatus(405);
    $this->post('/history/'.$log->id)->assertStatus(405);
    $this->delete('/history/999999')->assertNotFound();

    expect($log->fresh())->not->toBeNull()->archived_at->toBeNull();
});

it('fails closed when a running request loses its history row', function () {
    $history = app(HistoryLogService::class);
    $log = $history->start('scan');
    ActivityLog::whereKey($log->id)->delete();

    $result = ValidationResult::failClosed('REQ-1', FailureCode::TokenUnavailable);

    expect(fn () => $history->recordValidation($log, $result))
        ->toThrow(HistoryWriteException::class, 'no longer exists');
});

it('shows the history management setting', function () {
    $this->get('/settings')->assertOk()->assertSee('Activity history')->assertSee('Archive / delete from Log')->assertSee('Enabled');
});
