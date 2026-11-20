<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Execution\ExecutionGate;
use App\Services\Execution\ExecutionService;
use App\Services\Execution\ProcessRunner;
use App\Services\Llm\LlmService;
use App\Services\Recon\ReconPipeline;
use App\Services\ScopeEnforcer\ScopeEnforcerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
| Stage D: every application route requires a signed-in, active account.
| Guests and inactive users never reach a controller, so the pipeline
| (LLM -> Scope Enforcer -> gate -> execution) never starts. No network, no
| real process: HTTP is faked and recorded, ProcessRunner is a strict mock.
*/

const PUBLIC_ROUTES = ['login', 'login.store', 'register', 'register.store'];

function appRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->reject(fn (RoutingRoute $r) => in_array($r->getName(), [...PUBLIC_ROUTES, 'logout'], true) || $r->uri() === 'up')
        ->values()
        ->all();
}

/** One concrete request (method + URL) per application route. */
function appRequests(): array
{
    $log = ActivityLog::create(['instruction' => 'existing', 'stage' => 'validate', 'outcome' => 'denied', 'decision' => 'DENY']);
    return collect(appRoutes())->map(function (RoutingRoute $r) use ($log) {
        $method = collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        $uri = '/'.ltrim(str_replace('{log}', (string) $log->id, $r->uri()), '/');
        expect(str_contains($uri, '{'))->toBeFalse("unfilled parameter in {$r->uri()}");

        return [$method, $uri === '//' ? '/' : $uri];
    })->all();
}

beforeEach(function () {
    config([
        'scope_enforcer.base_url' => 'http://enforcer.test',
        'llm.base_url' => 'http://127.0.0.1:11434',
        'history.management_enabled' => true,
    ]);
    Http::fake(); // records every request; nothing may be sent

    $this->runner = Mockery::mock(ProcessRunner::class);
    $this->runner->shouldNotReceive('run');
    app()->instance(ProcessRunner::class, $this->runner);

    // detect any attempt to build a pipeline service
    $this->resolved = [];
    foreach ([ReconPipeline::class, LlmService::class, ScopeEnforcerClient::class, ExecutionGate::class, ExecutionService::class] as $class) {
        app()->resolving($class, function () use ($class) {
            $this->resolved[] = $class;
        });
    }
});

function assertPipelineUntouched(int $logsBefore): void
{
    Http::assertNothingSent();
    expect(test()->resolved)->toBe([])
        ->and(ActivityLog::count())->toBe($logsBefore);
}

it('protects every non-public route with auth and active', function () {
    $routes = appRoutes();
    expect(count($routes))->toBeGreaterThanOrEqual(12);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();
        expect(in_array('auth', $middleware, true))->toBeTrue("{$route->uri()} lacks auth")
            ->and(in_array('active', $middleware, true))->toBeTrue("{$route->uri()} lacks active");
    }
});

it('leaves only login, register and /up public', function () {
    $public = collect(Route::getRoutes()->getRoutes())
        ->reject(fn (RoutingRoute $r) => in_array('auth', $r->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $r) => $r->getName() ?? $r->uri())
        ->sort()->values()->all();

    expect($public)->toBe(['login', 'login.store', 'register', 'register.store', 'up']);
    $this->get('/up')->assertOk();
});

it('redirects guests to the login page on every application route', function () {
    $requests = appRequests();
    $before = ActivityLog::count();

    foreach ($requests as [$method, $uri]) {
        $this->call($method, $uri, ['instruction' => 'scan services on 192.168.100.50 port 80'])
            ->assertRedirect(route('login'));
    }
    $this->assertGuest();
    assertPipelineUntouched($before);
});

it('answers 401 to guests asking for JSON', function () {
    $this->postJson('/recon', ['instruction' => 'scan services on 192.168.100.50 port 80'])->assertUnauthorized();
    assertPipelineUntouched(0);
});

it('signs out inactive users on every application route without running anything', function () {
    $requests = appRequests();
    $before = ActivityLog::count();
    $inactive = User::factory()->inactive()->create();

    foreach ($requests as [$method, $uri]) {
        $this->actingAs($inactive)
            ->call($method, $uri, ['instruction' => 'scan services on 192.168.100.50 port 80'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => App\Http\Requests\Auth\LoginRequest::INACTIVE]);
        $this->assertGuest();
    }
    assertPipelineUntouched($before);
});

it('answers 403 to inactive users asking for JSON', function () {
    $this->actingAs(User::factory()->inactive()->create())
        ->postJson('/recon', ['instruction' => 'scan services on 192.168.100.50 port 80'])
        ->assertForbidden();
    assertPipelineUntouched(0);
});

it('ends the session of a user deactivated while signed in', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/status')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get('/recon')->assertRedirect(route('login'));
    $this->assertGuest();
    $this->post('/recon', ['instruction' => 'scan services on 192.168.100.50 port 80'])->assertRedirect(route('login'));
    expect(ActivityLog::count())->toBe(0);
});

it('lets an active user reach the application pages', function () {
    $this->actingAs(User::factory()->create());
    Http::fake([
        'enforcer.test/health' => Http::response(['status' => 'ok']),
        '127.0.0.1:11434/api/tags' => Http::response(['models' => []]),
    ]);

    foreach (['/', '/recon', '/history', '/validations', '/status', '/settings'] as $uri) {
        $this->get($uri)->assertOk();
    }
});

it('shows a logout button (POST with CSRF) to signed-in users only', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    $html = $this->get('/recon')->assertOk()->getContent();
    expect($html)->toMatch('#<form method="POST" action="'.preg_quote(route('logout'), '#').'" class="logout-form">\s*<input type="hidden" name="_token"#');
});
