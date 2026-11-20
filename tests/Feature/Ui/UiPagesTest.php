<?php

use App\Models\ActivityLog;
use App\Services\Execution\SignatureCheck;
use App\Services\Execution\SignatureVerifier;
use App\Services\ScopeEnforcer\ValidationRequest;
use App\Services\ScopeEnforcer\ValidationResult;
use App\Services\Status\SystemStatusService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/*
| Page rendering tests. Health endpoints are faked; rows are inserted
| directly. No network, no secrets (fake token in a temp file).
*/

const UI_SE = 'http://enforcer.test';
const UI_TOKEN = 'fake-ui-token-NeverShowMe-123456';

function uiRow(array $o = []): ActivityLog
{
    return ownedBy(ActivityLog::create(array_replace([
        'instruction' => 'scan services on 192.168.100.50 port 80',
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

/**
 * Set the faked health responses for this test. The single Http::fake in
 * beforeEach reads these at request time (Http::fake stubs are evaluated in
 * registration order and the first match wins, so we never stack fakes).
 */
function uiHealth(Closure|array $se, Closure|array $llm): void
{
    $toClosure = fn (Closure|array $r) => $r instanceof Closure ? $r : fn () => Http::response($r[0], $r[1]);
    test()->seHealth = $toClosure($se);
    test()->llmHealth = $toClosure($llm);
}

function uiRefused(): Closure
{
    return fn (Request $r) => Create::rejectionFor(new ConnectException('refused', $r->toPsrRequest(), null, ['errno' => 7]));
}

beforeEach(function () {
    signIn();
    Sleep::fake();
    $this->dir = sys_get_temp_dir().'/shellseek-ui-test-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0700);
    file_put_contents($this->dir.'/token', UI_TOKEN);
    chmod($this->dir.'/token', 0600);
    config([
        'scope_enforcer.base_url' => UI_SE,
        'scope_enforcer.token_file' => $this->dir.'/token',
        'scope_enforcer.signing_secret_file' => $this->dir.'/signing-missing',
        'llm.base_url' => 'http://127.0.0.1:11434',
    ]);
    uiHealth([['status' => 'ok'], 200], [['models' => [['name' => 'deepseek-coder:6.7b']]], 200]);
    Http::fake([
        'enforcer.test/health' => fn (Request $r) => ($this->seHealth)($r),
        '127.0.0.1:11434/api/tags' => fn (Request $r) => ($this->llmHealth)($r),
    ]);
});

afterEach(function () {
    @unlink($this->dir.'/token');
    @rmdir($this->dir);
});

// ------------------------------------------------------------- every page

it('renders every page with the main navigation (Recon Workspace, Dashboard, Log), the secondary links and no external assets', function (string $url, string $title) {
    $html = $this->get($url)->assertOk()->assertSee($title)->getContent();

    // main navigation: exactly Recon Workspace, Dashboard, Log (in this order) (no New Session button)
    preg_match('#<nav class="nav" aria-label="Main">(.*?)</nav>#s', $html, $main);
    preg_match_all('#data-nav="([^"]+)"#', $main[1] ?? '', $items);
    expect($items[1])->toBe(['Recon Workspace', 'Dashboard', 'Log']);

    // the other pages stay reachable from the sidebar footer
    preg_match('#<nav class="foot-links" aria-label="More">(.*?)</nav>#s', $html, $more);
    foreach (['Validation Results', 'System Status', 'Settings'] as $item) {
        expect($more[1] ?? '')->toContain('>'.$item.'</a>');
    }

    expect($more[1] ?? '')->not->toContain('Activity History');
    expect($html)->not->toContain('New Session')
        ->and($html)->not->toContain('data-new-session')
        ->and($html)->not->toContain('Fail-closed mode: on') // status moved to the dashboard
        ->and($html)->toContain('/css/app.css')
        ->and($html)->toContain('/js/app.js')
        ->and(preg_match('#(src|href)="(https?:)?//(?!localhost)#', $html))->toBe(0, 'external asset referenced');
})->with([
    'dashboard' => ['/', 'open log'],
    'reconnaissance' => ['/recon', 'Describe a recon task'],
    'validations' => ['/validations', 'Decisions returned by the Scope Enforcer'],
    'history' => ['/history', 'Every request, including failures'],
    'status' => ['/status', 'Fail-closed mode: always on'],
    'settings' => ['/settings', 'Secret files'],
]);

it('shows the shellseek wordmark image as the sidebar logo', function () {
    $this->get('/')->assertOk()
        ->assertSee('class="brand-logo" src="'.asset('images/shellseek-wordmark.png').'" alt="shellseek"', false)
        ->assertDontSee('brand-mark', false)
        ->assertDontSee('scope-enforced recon');
    expect(public_path('images/shellseek-wordmark.png'))->toBeFile();
});

it('marks the current page in the navigation', function () {
    $this->get('/history')->assertOk()->assertSee('aria-current="page"', false);
    expect($this->get('/recon')->assertOk()->getContent())
        ->toMatch('#class="active"\s+aria-current="page"\s+data-nav="Recon Workspace"#')
        ->not->toMatch('#aria-current="page"\s+data-nav="Dashboard"#');
});

// --------------------------------------------------------------- sidebar

it('has no sessions list in the sidebar', function () {
    uiRow(['instruction' => 'a stored request instruction']);

    $html = $this->get('/recon')->assertOk()->assertDontSee('Sessions')->getContent();
    preg_match('#<aside class="sidebar".*?</aside>#s', $html, $side);
    expect($side[0] ?? '')->not->toContain('a stored request instruction')->not->toContain('class="session');
});

it('shows no health status in the sidebar and never checks health to render it', function () {
    foreach (['/recon', '/history', '/validations', '/settings'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        preg_match('#<div class="sidebar-foot">(.*?)</div>#s', $html, $foot);
        expect($foot[1] ?? '')->not->toContain('Scope Enforcer')->not->toContain('Local LLM')->not->toContain('dot');
    }
    Http::assertNothingSent();
});

it('shows Scope Enforcer, LLM, execution and fail-closed status on the dashboard', function () {
    $this->get('/')->assertOk()
        ->assertSee('id="dash-se">healthy<', false)
        ->assertSee('id="dash-llm">ready<', false)
        ->assertSee('deepseek-coder:6.7b')
        ->assertSee('signature verification not configured')
        ->assertSee('fail-closed');
});

it('refreshes the dashboard health on demand', function () {
    $this->get('/')->assertOk();
    $this->get('/')->assertOk();
    Http::assertSentCount(2); // cached
    $this->get('/?refresh=1')->assertOk();
    Http::assertSentCount(4);
});

it('draws the decision ratio bar only when there are decisions', function () {
    $this->get('/')->assertOk()->assertDontSee('class="ratio"', false);
    uiRow(['decision' => 'ALLOW', 'risk_level' => 'LOW']);
    uiRow();
    $this->get('/')->assertOk()
        ->assertSee('class="ratio"', false)
        ->assertSee('class="r-ok" style="width: 50%"', false)
        ->assertSee('class="r-deny" style="width: 50%"', false);
});

// ------------------------------------------------------- reconnaissance UI

it('shows the start screen: logo, centred composer, key hints and footer', function () {
    $this->get('/recon')->assertOk()
        ->assertSee('class="recon-home is-home" data-home', false)
        ->assertSee('data-welcome', false)
        ->assertSee('placeholder="Describe a recon task... &quot;Scan services on &lt;approved lab target IP&gt; ports 80 and 443&quot;"', false)
        ->assertSee('class="composer-wrap composer-home"', false)
        ->assertSee('<span class="composer-mode"', false)
        ->assertSee(config('llm.model'))
        ->assertSee('<b>enter</b> send <b>shift+enter</b> new line', false)
        ->assertSee('class="home-foot"', false)
        ->assertSee('scope enforcer '.parse_url(config('scope_enforcer.base_url'), PHP_URL_HOST).' · fail-closed')
        ->assertSee('name="instruction"', false)
        ->assertSee('action="'.route('recon.store').'"', false)
        ->assertSee('class="welcome-logo" src="'.asset('images/shellseek-logo.png').'" alt="shellseek"', false);

    expect(public_path('images/shellseek-logo.png'))->toBeFile();
});

it('renders a stored request as a conversation: instruction, trace, command, verdict, terminal', function () {
    $log = uiRow([
        'decision' => 'ALLOW', 'risk_level' => 'LOW', 'execution_allowed' => true, 'reasons' => [],
        'validation' => ['command_valid' => true, 'target_valid' => true, 'tool_valid' => true, 'parameters_valid' => true],
        'outcome' => 'executed', 'stage' => 'execute', 'execution_status' => 'completed', 'exit_code' => 0,
        'argv' => ['/usr/bin/nmap', '-sV', '-p', '80', '192.168.100.50'], 'stdout' => '80/tcp open http',
    ]);

    $html = $this->get('/recon/'.$log->id)->assertOk()
        ->assertSee('class="msg msg-user"', false)
        ->assertSee('scan services on 192.168.100.50 port 80')
        ->assertSee('class="trace"', false)
        ->assertSee('Generated command')
        ->assertSee('<code class="param">-sV</code>', false)
        ->assertSee('class="tool verdict tone-ok"', false)
        ->assertSee('class="badge badge-allow" data-decision="ALLOW"', false)
        ->assertSee('parameters_valid: true')
        ->assertSee('Policy version')
        ->assertSee('Terminal')
        ->assertSee('80/tcp open http')
        ->assertSee('placeholder="Describe your reconnaissance task..."', false)
        ->getContent();

    // the verdict is visually separate from the LLM output and comes after it
    expect(strpos($html, 'Generated command'))->toBeLessThan(strpos($html, 'class="tool verdict'))
        ->and(strpos($html, 'class="tool verdict'))->toBeLessThan(strpos($html, 'class="tool terminal"'));
});

it('colours the verdict by decision and marks fail-closed as DENY', function (array $row, string $tone, string $badge) {
    $log = uiRow($row);

    $this->get('/recon/'.$log->id)->assertOk()
        ->assertSee('class="tool verdict tone-'.$tone.'"', false)
        ->assertSee($badge, false);
})->with([
    'warning' => [['decision' => 'WARNING', 'risk_level' => 'MEDIUM', 'outcome' => 'warning'], 'warn', 'data-decision="WARNING"'],
    'deny' => [[], 'deny', 'data-decision="DENY"'],
    'fail-closed' => [['origin' => 'fail_closed', 'failure_code' => 'unreachable', 'risk_level' => null, 'outcome' => 'fail_closed'], 'deny', '· treated as DENY'],
]);

it('shows no terminal panel when nothing was executed', function () {
    $log = uiRow();

    $this->get('/recon/'.$log->id)->assertOk()->assertDontSee('class="tool terminal"', false)->assertSee('not executed');
});

it('shows the raw LLM output when no command could be parsed', function () {
    $log = uiRow(['tool' => null, 'action' => null, 'target' => null, 'parameters' => null, 'decision' => null,
        'outcome' => 'parse_failed', 'parse_detail' => 'LLM output is not valid JSON.', 'llm_output' => 'Sure! nmap -sV x']);

    $this->get('/recon/'.$log->id)->assertOk()
        ->assertSee('LLM output is not valid JSON.')
        ->assertSee('Sure! nmap -sV x')
        ->assertSee('Not sent to the Scope Enforcer.');
});

it('returns 404 for an unknown request page', function () {
    $this->get('/recon/999999')->assertNotFound();
});

// -------------------------------------------------------------- dashboard

it('shows decision counts and recent activity on the dashboard', function () {
    uiRow(['decision' => 'ALLOW', 'risk_level' => 'LOW', 'outcome' => 'executed', 'execution_allowed' => true, 'reasons' => []]);
    uiRow(['decision' => 'WARNING', 'risk_level' => 'MEDIUM', 'outcome' => 'warning']);
    uiRow();
    uiRow(['origin' => 'fail_closed', 'failure_code' => 'unreachable', 'risk_level' => null, 'outcome' => 'fail_closed']);
    uiRow(['decision' => null, 'outcome' => 'llm_failed', 'tool' => null]);

    $this->get('/')->assertOk()
        ->assertSee('data-stat="allow">1<', false)
        ->assertSee('data-stat="warning">1<', false)
        ->assertSee('data-stat="deny">2<', false)
        ->assertSee('(1 fail-closed)')
        ->assertSee('data-stat="executed">1<', false)
        ->assertSee('data-stat="total">5<', false);
});

it('warns on the dashboard when the Scope Enforcer is down', function () {
    uiHealth(uiRefused(), [['models' => []], 200]);

    $this->get('/')->assertOk()
        ->assertSee('Scope Enforcer unavailable – all requests are treated as DENY.')
        ->assertSee('id="dash-se">unreachable<', false)
        ->assertSee('– treated as DENY');
});

// -------------------------------------------------------------------- log

it('shows the log as a timeline with prompt, command, decision and execution status', function () {
    uiRow(['instruction' => 'allowed and executed prompt', 'decision' => 'ALLOW', 'risk_level' => 'LOW', 'reasons' => [],
        'outcome' => 'executed', 'execution_status' => 'completed', 'exit_code' => 0]);
    uiRow(['instruction' => 'warning prompt', 'decision' => 'WARNING', 'risk_level' => 'MEDIUM', 'outcome' => 'warning',
        'reasons' => ['Timing -T4 requires review']]);
    uiRow(['instruction' => 'denied prompt']);
    uiRow(['instruction' => 'llm failed prompt', 'decision' => null, 'tool' => null, 'outcome' => 'llm_failed', 'reasons' => null]);

    $html = $this->get('/history')->assertOk()
        ->assertSee('<title>Log · ShellSeek</title>', false)
        ->assertSee('id="log"', false)
        ->assertSee('allowed and executed prompt')
        ->assertSee('nmap -sV -p 80 192.168.100.50')
        ->assertSee('completed · exit 0')
        ->assertSee('held – not executed')
        ->assertSee('Timing -T4 requires review')
        ->assertSee('no command generated')
        ->assertSee('data-decision="ALLOW"', false)
        ->assertSee('data-decision="WARNING"', false)
        ->assertSee('data-decision="DENY"', false)
        ->assertSee('data-decision="none"', false)
        ->getContent();

    // newest first, every entry links to its session
    expect(strpos($html, 'llm failed prompt'))->toBeLessThan(strpos($html, 'allowed and executed prompt'))
        ->and(substr_count($html, 'class="log-entry"'))->toBe(4)
        ->and($html)->toContain('class="log-prompt" href="'.route('recon.show', ActivityLog::where('instruction', 'denied prompt')->sole()).'"');
});

function uiLogList(string $html): string
{
    preg_match('#<ol class="log" id="log">(.*?)</ol>#s', $html, $m);

    return $m[1] ?? '';
}

it('filters the log by decision and keeps the outcome filter', function () {
    uiRow(['instruction' => 'log allow row', 'decision' => 'ALLOW', 'risk_level' => 'LOW', 'outcome' => 'gate_refused']);
    uiRow(['instruction' => 'log deny row']);

    // (the sidebar lists every session, so only the log list itself is inspected)
    $allow = uiLogList($this->get('/history?decision=ALLOW')->assertOk()->assertSee('1 entry')->getContent());
    expect($allow)->toContain('log allow row')->not->toContain('log deny row');
    $deny = uiLogList($this->get('/history?decision=DENY&outcome=denied')->assertOk()->getContent());
    expect($deny)->toContain('log deny row')->not->toContain('log allow row');
    $this->get('/history?decision=DENY&outcome=executed')->assertOk()->assertSee('No activity recorded for this filter');
    $this->get('/history?decision=bogus')->assertOk()->assertSee('2 entries');
});

// ------------------------------------------------------------------- theme

it('uses a flat, square, monochrome theme where blue is limited to the send button and composer mode (the logo is an image)', function () {
    $css = file_get_contents(public_path('css/app.css'));
    $rules = preg_replace('#/\*.*?\*/#s', '', $css); // ignore comments

    expect($rules)->not->toContain('gradient')
        ->and(preg_match('/box-shadow\s*:/', $rules))->toBe(0)
        ->and($rules)->not->toContain('text-shadow')
        // square corners everywhere
        ->and(preg_match_all('/border-radius\s*:\s*([^;]+);/', $rules, $radii))->toBeGreaterThan(0)
        ->and(array_unique(array_map('trim', $radii[1])))->toBe(['0'])
        // blue is defined once (the logo variable)
        ->and(preg_match_all('/#(3B82F6|2563EB|60A5FA|7AA7F7|22D3EE)\b/i', $rules))->toBe(1)
        // monospace is the default UI font; sans only for explanatory text
        ->and($rules)->toMatch('/body\s*\{[^}]*var\(--mono\)/');

    // ... and used only by an allowlisted set of rules
    preg_match_all('/([^{}]+)\{[^}]*var\(--logo\)[^}]*\}/', $rules, $blue);
    expect(array_map('trim', $blue[1]))->toBe(['.composer-mode', '.composer-send', '.is-home .composer-box']);

    // dashboard, Recon Workspace and Log use the full width (left and right aligned)
    foreach (['.content', '.thread', '.composer'] as $selector) {
        preg_match('/(^|\n)'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', $rules, $rule);
        expect($rule)->not->toBeEmpty("{$selector} rule missing")
            ->and($rule[2] ?? '')->not->toContain('max-width')->not->toContain('margin: 0 auto');
    }
});

it('gives buttons hover, press and focus feedback and honours reduced motion', function () {
    $rules = preg_replace('#/\*.*?\*/#s', '', file_get_contents(public_path('css/app.css')));

    foreach (['.btn:hover', '.btn:active', '.btn-ghost:hover', '.chip:active', '.composer-send:hover',
        '.composer-send:active', '.copy:active', '.nav a:hover::before', '.nav a.active::before',
        '.example:hover::after', '.log-entry:hover::before', '.btn:focus-visible'] as $selector) {
        expect($rules)->toContain($selector);
    }
    // the send button is dimmed while the composer is empty
    expect($rules)->toContain('.composer-box:has(textarea:placeholder-shown) .composer-send')
        // and every animation is switched off for users who ask for reduced motion
        ->and($rules)->toMatch('/prefers-reduced-motion: reduce\)\s*\{\s*\*, \*::before, \*::after \{ transition: none !important; animation: none !important; \}/');
});

// ------------------------------------------------------------------ badges

it('uses green/yellow/red badges for ALLOW/WARNING/DENY and labels fail-closed', function () {
    uiRow(['decision' => 'ALLOW', 'risk_level' => 'LOW', 'outcome' => 'executed']);
    uiRow(['decision' => 'WARNING', 'risk_level' => 'MEDIUM', 'outcome' => 'warning']);
    uiRow(['decision' => 'DENY']);
    uiRow(['decision' => 'DENY', 'origin' => 'fail_closed', 'risk_level' => null, 'outcome' => 'fail_closed']);

    $this->get('/validations')->assertOk()
        ->assertSee('class="badge badge-allow" data-decision="ALLOW"', false)
        ->assertSee('class="badge badge-warning" data-decision="WARNING"', false)
        ->assertSee('class="badge badge-deny" data-decision="DENY"', false)
        ->assertSee('· treated as DENY', false);

    $css = file_get_contents(public_path('css/app.css'));
    expect($css)->toContain('--ok: #2fbf71')->toContain('--warn: #f2b233')->toContain('--deny: #ef5350')
        ->toContain('.badge-allow')->toContain('.badge-warning')->toContain('.badge-deny');
});

it('filters validation results by decision', function () {
    uiRow(['decision' => 'ALLOW', 'risk_level' => 'LOW', 'request_id' => 'REQ-allow-row']);
    uiRow(['decision' => 'DENY', 'request_id' => 'REQ-deny-row']);

    $this->get('/validations?decision=ALLOW')->assertOk()->assertSee('REQ-allow-row')->assertDontSee('REQ-deny-row');
    $this->get('/validations?decision=bogus')->assertOk()->assertSee('REQ-allow-row')->assertSee('REQ-deny-row');
});

// ----------------------------------------------------------- detail page

it('shows reasons, validation flags and execution details for a request', function () {
    $log = uiRow([
        'decision' => 'ALLOW', 'risk_level' => 'LOW', 'execution_allowed' => true, 'reasons' => [],
        'validation' => ['command_valid' => true, 'target_valid' => true, 'tool_valid' => true, 'parameters_valid' => true],
        'authorization_issued_at' => 1_700_000_000, 'authorization_expires_at' => 1_700_000_060,
        'gate_refusal' => 'signature_unavailable', 'outcome' => 'gate_refused', 'stage' => 'gate',
    ]);

    $this->get('/validations/'.$log->id)->assertOk()
        ->assertSee($log->request_id)
        ->assertSee('parameters_valid: true')
        ->assertSee('valid 60 s')
        ->assertSee('signature cannot be verified');
});

it('returns 404 for an unknown request', function () {
    $this->get('/validations/999999')->assertNotFound();
});

// ---------------------------------------------------------------- history

it('filters and paginates the activity history', function () {
    foreach (range(1, 30) as $i) {
        uiRow(['instruction' => "denied instruction {$i}"]);
    }
    uiRow(['instruction' => 'the warning one', 'decision' => 'WARNING', 'outcome' => 'warning', 'risk_level' => 'MEDIUM']);

    $this->get('/history')->assertOk()->assertSee('31 entries')->assertSee('Page 1 / 2')->assertSee('rel="next"', false);
    $this->get('/history?page=2')->assertOk()->assertSee('Page 2 / 2');
    $this->get('/history?outcome=warning')->assertOk()->assertSee('the warning one')->assertDontSee('denied instruction 1<', false)->assertSee('1 entry');
    $this->get('/history?outcome=not-a-real-outcome')->assertOk()->assertSee('31 entries');
});

it('escapes untrusted stored data everywhere', function () {
    $evil = '<img src=x onerror=alert(1)>';
    $log = uiRow(['instruction' => $evil, 'target' => $evil, 'reasons' => [$evil], 'stdout' => $evil, 'execution_status' => 'completed', 'outcome' => 'executed']);

    foreach (['/', '/history', '/validations', '/validations/'.$log->id, '/recon/'.$log->id] as $url) {
        $this->get($url)->assertOk()->assertDontSee($evil, false)->assertSee(e($evil), false);
    }
});

// ----------------------------------------------------------------- status

it('shows the Scope Enforcer as unreachable and treated as DENY', function () {
    uiHealth(uiRefused(), [['models' => [['name' => 'deepseek-coder:6.7b']]], 200]);

    $this->get('/status')->assertOk()
        ->assertSee('id="se-state">Unreachable<', false)
        ->assertSee('every request is treated as DENY')
        ->assertSee('id="llm-state">Ready<', false)
        ->assertSee('Unavailable – no command can execute');
});

it('shows an unhealthy Scope Enforcer (503) and a missing model', function () {
    uiHealth([['status' => 'unavailable'], 503], [['models' => [['name' => 'other:1b']]], 200]);

    $this->get('/status')->assertOk()
        ->assertSee('id="se-state">Unhealthy<', false)
        ->assertSee('unavailable')
        ->assertSee('id="llm-state">Model missing<', false);
});

it('shows healthy services but still blocks execution until signatures can be verified', function () {
    $this->get('/status')->assertOk()
        ->assertSee('id="se-state">Healthy<', false)
        ->assertSee('Signature verification is not configured, so no command can be executed');
});

it('reports execution as possible only with a healthy enforcer and an active verifier', function () {
    app()->instance(SignatureVerifier::class, new class implements SignatureVerifier
    {
        public function verify(ValidationRequest $command, ValidationResult $result): SignatureCheck
        {
            return SignatureCheck::Valid;
        }
    });

    $this->get('/status?refresh=1')->assertOk()
        ->assertSee('HMAC-SHA256 verifier active')
        ->assertSee('Commands run only with a signed, unexpired ALLOW');
});

it('caches health checks briefly and refreshes on demand', function () {
    $this->get('/status')->assertOk();
    $this->get('/')->assertOk();
    Http::assertSentCount(2); // one /health + one /api/tags, then cached

    $this->get('/status?refresh=1')->assertOk();
    Http::assertSentCount(4);
    expect(SystemStatusService::CACHE_SECONDS)->toBe(15);
});

it('never sends the API token to the health endpoint', function () {
    $this->get('/status')->assertOk();

    Http::assertSent(fn (Request $r) => ! str_contains($r->url(), '/health') || ! $r->hasHeader('Authorization'));
});

// --------------------------------------------------------------- settings

it('shows configuration and secret file status but never secret contents', function () {
    $html = $this->get('/settings')->assertOk()
        ->assertSee(UI_SE)
        ->assertSee('deepseek-coder:6.7b')
        ->assertSee('/usr/bin/nmap')
        ->assertSee('present · 0600')
        ->assertSee('missing')
        ->assertSee('Not configured – execution disabled')
        ->getContent();

    expect(str_contains($html, UI_TOKEN))->toBeFalse('token content shown on settings page');
});

it('flags an insecure secret file', function () {
    chmod($this->dir.'/token', 0644);

    $html = $this->get('/settings')->assertOk()->assertSee('insecure · 0644')->getContent();
    expect(str_contains($html, UI_TOKEN))->toBeFalse();
});

it('never renders any secret on any page', function () {
    uiRow(['instruction' => 'masked already: ********']);

    foreach (['/', '/recon', '/recon/'.ActivityLog::sole()->id, '/validations', '/history', '/status', '/settings'] as $url) {
        expect(str_contains($this->get($url)->getContent(), UI_TOKEN))->toBeFalse("token on {$url}");
    }
});
