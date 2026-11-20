<?php

use App\Services\Execution\AuthorizedCommand;
use App\Services\Execution\ExecutionService;
use App\Services\Execution\ExecutionStatus;
use App\Services\Execution\ProcessRunner;
use App\Services\Execution\SymfonyProcessRunner;
use Illuminate\Support\Carbon;

/*
| REAL SymfonyProcessRunner, but "nmap" is mapped to a FAKE PHP script
| (tests/Fixtures/fake-tool.php) that does no network activity.
| Real nmap/whois never run.
*/

const X_NOW = 1_700_000_000;

function xCmd(array $o = []): AuthorizedCommand
{
    return new AuthorizedCommand(
        'REQ-proc-001', 'nmap', 'service_detection',
        $o['target'] ?? '192.168.100.50', $o['parameters'] ?? ['echo'], '1.0', X_NOW + 30,
    );
}

function xFakeTool(string $dir): string
{
    $path = $dir.'/fake-tool';
    file_put_contents($path, '#!'.PHP_BINARY."\n".file_get_contents(base_path('tests/Fixtures/fake-tool.php')));
    chmod($path, 0700);

    return $path;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(X_NOW));
    $this->workdir = sys_get_temp_dir().'/shellseek-proc-test-'.bin2hex(random_bytes(6));
    config(['execution.working_directory' => $this->workdir]);
    $this->toolDir = sys_get_temp_dir().'/shellseek-faketool-'.bin2hex(random_bytes(6));
    mkdir($this->toolDir, 0700);
    config(['tools.nmap.binary' => xFakeTool($this->toolDir)]);
    app()->instance(ProcessRunner::class, new SymfonyProcessRunner);
});

afterEach(function () {
    Carbon::setTestNow();
    foreach (glob($this->workdir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->workdir);
    @unlink($this->toolDir.'/fake-tool');
    @rmdir($this->toolDir);
});

it('passes shell metacharacters literally (no shell involved)', function () {
    $params = ['echo', '$(id)', ';', '|', '&&', '`id`', '*', 'a b', '>out', '$HOME'];

    $result = app(ExecutionService::class)->run(xCmd(['parameters' => $params]));

    expect($result->status)->toBe(ExecutionStatus::Completed)
        ->and($result->exitCode)->toBe(0)
        ->and(json_decode($result->stdout, true))->toBe([...$params, '192.168.100.50'])
        ->and(file_exists($this->workdir.'/out'))->toBeFalse();
});

it('gives the child only PATH and LANG (no .env values or other variables)', function () {
    putenv('SHELLSEEK_CANARY=leak-me');
    $_ENV['SHELLSEEK_ENV_CANARY'] = 'leak-me-too';
    $_SERVER['SHELLSEEK_SERVER_CANARY'] = 'leak-me-three';

    try {
        $result = app(ExecutionService::class)->run(xCmd(['parameters' => ['env']]));
    } finally {
        putenv('SHELLSEEK_CANARY');
        unset($_ENV['SHELLSEEK_ENV_CANARY'], $_SERVER['SHELLSEEK_SERVER_CANARY']);
    }

    $env = json_decode($result->stdout, true);
    expect($env)->toBe(['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8'])
        ->and($result->stdout)->not->toContain('leak-me')
        ->and($result->stdout)->not->toContain('APP_KEY');
});

it('reports exit code and stderr of a failing process', function () {
    $result = app(ExecutionService::class)->run(xCmd(['parameters' => ['exit3']]));

    expect($result->status)->toBe(ExecutionStatus::Completed)
        ->and($result->exitCode)->toBe(3)
        ->and($result->stderr)->toBe('simulated failure');
});

it('kills a process that exceeds its timeout', function () {
    config(['tools.nmap.timeout' => 1]);

    $started = microtime(true);
    $result = app(ExecutionService::class)->run(xCmd(['parameters' => ['sleep']]));
    $elapsed = microtime(true) - $started;
    fwrite(STDERR, sprintf("\n    [timeout] elapsed=%.2fs status=%s\n", $elapsed, $result->status->value));

    expect($result->status)->toBe(ExecutionStatus::TimedOut)
        ->and($result->exitCode)->toBeNull()
        ->and($elapsed)->toBeGreaterThanOrEqual(0.9)
        ->and($elapsed)->toBeLessThan(3.0);
});

it('stops a process that exceeds the output cap', function () {
    config(['execution.max_output_bytes' => 4096]);

    $started = microtime(true);
    $result = app(ExecutionService::class)->run(xCmd(['parameters' => ['flood']]));
    $elapsed = microtime(true) - $started;
    fwrite(STDERR, sprintf("\n    [output cap] elapsed=%.2fs captured=%d bytes status=%s\n", $elapsed, strlen($result->stdout), $result->status->value));

    expect($result->status)->toBe(ExecutionStatus::OutputLimitExceeded)
        ->and(strlen($result->stdout) + strlen($result->stderr))->toBeLessThanOrEqual(4096)
        ->and($elapsed)->toBeLessThan(5.0);
});
