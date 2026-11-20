<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Execution\ProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
| No roles: every account sees and manages only its own logs; there is no
| admin page. The user identity is stored locally and never sent to the
| Scope Enforcer. HTTP is faked, ProcessRunner is a strict mock.
*/

function azLog(?User $owner, array $o = []): ActivityLog
{
    return ownedBy(ActivityLog::create(array_replace([
        'instruction' => 'az prompt',
        'stage' => 'validate',
        'outcome' => 'denied',
        'request_id' => 'REQ-'.bin2hex(random_bytes(6)),
        'tool' => 'nmap',
        'target' => '192.168.100.50',
        'parameters' => ['-sV'],
        'decision' => 'DENY',
        'risk_level' => 'HIGH',
        'execution_allowed' => false,
        'reasons' => ['r'],
        'origin' => 'backend',
    ], $o)), $owner);
}

function azList(string $html): string
{
    preg_match('#<ol class="log" id="log">(.*?)</ol>#s', $html, $m);

    return $m[1] ?? '';
}

beforeEach(function () {
    config([
        'history.management_enabled' => true,
        'scope_enforcer.base_url' => 'http://enforcer.test',
        'scope_enforcer.token_file' => sys_get_temp_dir().'/shellseek-az-missing-'.bin2hex(random_bytes(4)),
        'llm.base_url' => 'http://127.0.0.1:11434',
    ]);
    Http::fake([
        'enforcer.test/health' => Http::response(['status' => 'ok']),
        '127.0.0.1:11434/api/tags' => Http::response(['models' => []]),
    ]);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(ProcessRunner::class, $runner);

    $this->alice = User::factory()->create(['name' => 'Alice', 'username' => 'alice']);
    $this->bob = User::factory()->create(['name' => 'Bob', 'username' => 'bob']);

    $this->aliceLog = azLog($this->alice, ['instruction' => 'alice prompt', 'decision' => 'ALLOW', 'risk_level' => 'LOW', 'outcome' => 'gate_refused']);
    $this->bobLog = azLog($this->bob, ['instruction' => 'bob prompt']);
    $this->legacyLog = azLog(null, ['instruction' => 'legacy prompt', 'decision' => 'WARNING', 'outcome' => 'warning']);
});

// ---------------------------------------------------------------- visibility

it('shows each account only its own logs on the Log page', function () {
    $alice = azList($this->actingAs($this->alice)->get('/history')->assertOk()->assertSee('1 entry')->getContent());
    expect($alice)->toContain('alice prompt')->not->toContain('bob prompt')->not->toContain('legacy prompt');

    $bob = azList($this->actingAs($this->bob)->get('/history')->assertOk()->assertSee('1 entry')->getContent());
    expect($bob)->toContain('bob prompt')->not->toContain('alice prompt')->not->toContain('legacy prompt');
});

it('counts only the account\'s own logs on the dashboard', function () {
    $this->actingAs($this->alice)->get('/')->assertOk()
        ->assertSee('data-stat="total">1<', false)
        ->assertSee('data-stat="allow">1<', false)
        ->assertSee('data-stat="deny">0<', false)
        ->assertSee('data-stat="warning">0<', false)
        ->assertDontSee('bob prompt')->assertDontSee('legacy prompt');
});

it('filters Validation Results by owner', function () {
    // the list shows request ids, not prompts
    $html = $this->actingAs($this->alice)->get('/validations')->assertOk()->getContent();
    expect($html)->toContain($this->aliceLog->request_id)
        ->not->toContain($this->bobLog->request_id)->not->toContain($this->legacyLog->request_id);
});

it('answers 404 for another account\'s or an ownerless log', function (string $route) {
    $this->actingAs($this->alice);

    $this->get(route($route, $this->aliceLog))->assertOk();
    $this->get(route($route, $this->bobLog))->assertNotFound();
    $this->get(route($route, $this->legacyLog))->assertNotFound();
})->with(['recon.show', 'validations.show']);

it('hides other accounts\' archived logs', function () {
    $this->bobLog->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->alice)->get('/history')->assertSee('Archived (0)');
    expect(azList($this->actingAs($this->alice)->get('/history?view=archived')->getContent()))->toBe('');
    $this->actingAs($this->bob)->get('/history')->assertSee('Archived (1)');
});

// ------------------------------------------------------- archive / delete

it('lets an account archive, restore and delete its own logs', function () {
    $this->actingAs($this->alice);

    $this->post(route('history.archive', $this->aliceLog))->assertRedirect();
    expect($this->aliceLog->fresh()->archived_at)->not->toBeNull();
    $this->post(route('history.restore', $this->aliceLog))->assertRedirect();
    expect($this->aliceLog->fresh()->archived_at)->toBeNull();
    $this->delete(route('history.destroy', $this->aliceLog))->assertRedirect();
    expect(ActivityLog::find($this->aliceLog->id))->toBeNull();
});

it('never lets an account archive, restore or delete someone else\'s or an ownerless log', function () {
    $this->actingAs($this->alice);
    $bobArchived = azLog($this->bob, ['instruction' => 'bob archived', 'archived_at' => now()]);

    $this->post(route('history.archive', $this->bobLog))->assertNotFound();
    $this->post(route('history.restore', $bobArchived))->assertNotFound();
    $this->delete(route('history.destroy', $this->bobLog))->assertNotFound();
    $this->delete(route('history.destroy', $this->legacyLog))->assertNotFound();

    expect($this->bobLog->fresh()->archived_at)->toBeNull()
        ->and($bobArchived->fresh()->archived_at)->not->toBeNull()
        ->and(ActivityLog::count())->toBe(4);
});

it('shows archive/delete buttons only for the account\'s own logs', function () {
    $html = $this->actingAs($this->alice)->get('/history')->getContent();
    expect($html)->toContain(route('history.archive', $this->aliceLog))
        ->not->toContain(route('history.archive', $this->bobLog));
});

// ------------------------------------------------------------ no admin

it('has no admin page or role anywhere in the UI', function () {
    expect(collect(Route::getRoutes()->getRoutes())->map->uri()->filter(fn ($u) => str_contains($u, 'users'))->all())->toBe([]);
    $this->actingAs($this->alice)->get('/settings/users')->assertNotFound();

    foreach (['/', '/recon', '/history', '/settings'] as $uri) {
        $html = $this->actingAs($this->alice)->get($uri)->assertOk()->getContent();
        expect(strtolower(strip_tags($html)))->not->toContain('admin')->not->toContain('analyst');
    }
});

// ------------------------------------------------- ownership + VM 2 contract

it('records the requesting account on new logs and sends no identity to the Scope Enforcer', function () {
    Http::fake([
        '127.0.0.1:11434/*' => Http::response(['model' => 'deepseek-coder:6.7b', 'done' => true, 'done_reason' => 'stop',
            'message' => ['role' => 'assistant', 'content' => '{"tool":"nmap","action":"service_detection","target":"192.168.100.50","parameters":["-sV","-p","80"]}']]),
        'enforcer.test/*' => fn (Request $r) => Http::response([
            'request_id' => $r->data()['request_id'], 'decision' => 'DENY', 'risk_level' => 'HIGH', 'execution_allowed' => false,
            'reasons' => ['Target not in scope'], 'policy_version' => '1.0', 'authorization' => null,
            'validation' => ['command_valid' => true, 'target_valid' => false, 'tool_valid' => true, 'parameters_valid' => true],
        ]),
    ]);
    $tokenFile = tempnam(sys_get_temp_dir(), 'shellseek-az-token-');
    file_put_contents($tokenFile, 'fake-az-token-0123456789abcdef');
    chmod($tokenFile, 0600);
    config(['scope_enforcer.token_file' => $tokenFile]);

    $this->actingAs($this->alice)
        ->post('/recon', ['instruction' => 'scan services on 192.168.100.50 port 80'])
        ->assertRedirect();
    @unlink($tokenFile);

    $log = ActivityLog::latest('id')->first();
    expect($log->user_id)->toBe($this->alice->id)->and($log->decision)->toBe('DENY');

    $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), '/api/v1/validate'))->map(fn ($p) => $p[0]);
    expect($sent)->toHaveCount(1);
    $body = $sent->first()->data();
    expect(array_keys($body))->toEqualCanonicalizing(['request_id', 'tool', 'action', 'target', 'parameters', 'requested_by'])
        ->and($body['requested_by'])->toBe('laravel-app');
    expect($sent->first()->body())->not->toContain('Alice')->not->toContain('alice')->not->toContain('"user_id"');
});
