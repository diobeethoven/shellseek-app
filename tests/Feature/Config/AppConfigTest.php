<?php

use App\Services\Execution\AuthorizedCommand;
use App\Services\Execution\ExecutionService;
use App\Services\Execution\ExecutionStatus;
use App\Services\Execution\ProcessOutcome;
use App\Services\Execution\ProcessRunner;
use Illuminate\Support\Carbon;

afterEach(function () {
    putenv('APP_TIMEZONE');
    unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);
    Carbon::setTestNow();
});

it('reads the timezone from APP_TIMEZONE with UTC as default', function () {
    putenv('APP_TIMEZONE');
    unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);
    expect((require config_path('app.php'))['timezone'])->toBe('UTC');

    putenv('APP_TIMEZONE=Asia/Jakarta');
    $_ENV['APP_TIMEZONE'] = $_SERVER['APP_TIMEZONE'] = 'Asia/Jakarta';
    expect((require config_path('app.php'))['timezone'])->toBe('Asia/Jakarta');
});

it('documents a local timezone in .env.example', function () {
    expect(file_get_contents(base_path('.env.example')))->toContain('APP_TIMEZONE=');
});

it('provides a serve-local script that binds to loopback only', function () {
    $scripts = json_decode(file_get_contents(base_path('composer.json')), true)['scripts'];
    $serve = implode(' ', $scripts['serve-local']);

    expect($serve)->toContain('artisan serve --host=127.0.0.1')
        ->not->toContain('0.0.0.0');
});

it('keeps the tool catalog provisional and free of policy decisions', function () {
    $tools = require config_path('tools.php');

    foreach ($tools as $name => $tool) {
        expect($tool)->toHaveKeys(['action', 'binary', 'timeout'])
            ->and(array_keys($tool))->each->toBeIn(['action', 'target', 'hint', 'binary', 'timeout'])
            ->and($tool['binary'])->toStartWith('/');
    }
    expect(file_get_contents(config_path('tools.php')))->toContain('PROVISIONAL');
});

it('executes a newly catalogued tool with the binary from the catalog', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));
    config([
        'tools.dig' => ['action' => 'dns_lookup', 'target' => 'a domain name', 'binary' => '/usr/bin/env', 'timeout' => 10],
        'execution.working_directory' => sys_get_temp_dir().'/shellseek-catalog-test',
    ]);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()
        ->withArgs(fn (array $argv, float $timeout) => $argv === ['/usr/bin/env', '+short', 'example.com'] && $timeout === 10.0)
        ->andReturn(new ProcessOutcome(0, '', '', 1, false, false));
    app()->instance(ProcessRunner::class, $runner);

    $cmd = new AuthorizedCommand('REQ-cat-1', 'dig', 'dns_lookup', 'example.com', ['+short'], '1.0', 1_700_000_030);

    expect(app(ExecutionService::class)->run($cmd)->status)->toBe(ExecutionStatus::Completed);
    @rmdir(sys_get_temp_dir().'/shellseek-catalog-test');
});
