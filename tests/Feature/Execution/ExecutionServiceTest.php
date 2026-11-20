<?php

use App\Services\Execution\AuthorizedCommand;
use App\Services\Execution\ExecutionService;
use App\Services\Execution\ExecutionStatus;
use App\Services\Execution\ProcessOutcome;
use App\Services\Execution\ProcessRunner;
use Illuminate\Support\Carbon;

/*
| Uses a mocked ProcessRunner: nothing is started here.
| Real-process behaviour is covered in ExecutionProcessTest (fake tool).
*/

const E_NOW = 1_700_000_000;

function eCmd(array $o = []): AuthorizedCommand
{
    return new AuthorizedCommand(
        'REQ-exec-001',
        $o['tool'] ?? 'nmap',
        $o['action'] ?? 'service_detection',
        $o['target'] ?? '192.168.100.50',
        $o['parameters'] ?? ['-sV', '-p', '80,443'],
        '1.0',
        $o['expires_at'] ?? E_NOW + 30,
    );
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(E_NOW));
    $this->workdir = sys_get_temp_dir().'/shellseek-exec-test-'.bin2hex(random_bytes(6));
    config(['execution.working_directory' => $this->workdir]);
});

afterEach(function () {
    Carbon::setTestNow();
    foreach (glob($this->workdir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->workdir);
});

it('builds argv without a shell and passes timeout, output cap, minimal env and cwd', function (array $cmd, array $argv, float $timeout) {
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()
        ->with($argv, $timeout, 1024 * 1024, ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8'], Mockery::type('string'))
        ->andReturn(new ProcessOutcome(0, 'ok', '', 5, false, false));
    app()->instance(ProcessRunner::class, $runner);

    $result = app(ExecutionService::class)->run(eCmd($cmd));

    expect($result->status)->toBe(ExecutionStatus::Completed)
        ->and($result->argv)->toBe($argv)
        ->and($result->exitCode)->toBe(0)
        ->and(is_dir($this->workdir))->toBeTrue();
})->with([
    'nmap' => [[], ['/usr/bin/nmap', '-sV', '-p', '80,443', '192.168.100.50'], 300.0],
    'whois' => [['tool' => 'whois', 'action' => 'domain_lookup', 'target' => 'example.com', 'parameters' => []], ['/usr/bin/whois', 'example.com'], 30.0],
]);

it('caps the timeout at the global maximum', function () {
    config(['tools.nmap.timeout' => 999]);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn ($argv, $timeout) => $timeout === 300.0)
        ->andReturn(new ProcessOutcome(0, '', '', 1, false, false));
    app()->instance(ProcessRunner::class, $runner);

    app(ExecutionService::class)->run(eCmd());
});

it('refuses locally unsafe commands without starting anything', function (Closure $setup, array $cmd) {
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(ProcessRunner::class, $runner);
    $setup();

    $result = app(ExecutionService::class)->run(eCmd($cmd));

    expect($result->status)->toBe(ExecutionStatus::Refused)
        ->and($result->wasStarted())->toBeFalse()
        ->and($result->reason)->not->toBeEmpty();
})->with([
    'tool without executable (dig)' => [fn () => null, ['tool' => 'dig']],
    'binary missing' => [fn () => config(['tools.nmap.binary' => '/nonexistent/nmap']), []],
    'relative binary' => [fn () => config(['tools.nmap.binary' => 'nmap']), []],
    'binary not executable' => [fn () => config(['tools.nmap.binary' => base_path('composer.json')]), []],
    'target is an option' => [fn () => null, ['target' => '-iL']],
    'empty target' => [fn () => null, ['target' => '']],
    'NUL byte in parameter' => [fn () => null, ['parameters' => ["-sV\0-A"]]],
    'expired before execution' => [fn () => null, ['expires_at' => E_NOW]],
    'zero timeout' => [fn () => config(['tools.nmap.timeout' => 0]), []],
]);

it('reports an error instead of throwing when the runner fails', function () {
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->andThrow(new RuntimeException('boom'));
    app()->instance(ProcessRunner::class, $runner);

    $result = app(ExecutionService::class)->run(eCmd());

    expect($result->status)->toBe(ExecutionStatus::Error)->and($result->wasStarted())->toBeFalse();
});
