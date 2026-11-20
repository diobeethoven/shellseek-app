<?php

use App\Support\SecretFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
| README.md and docs/architecture.md must stay accurate and secret-free.
*/

function docs(): array
{
    return [
        'README.md' => file_get_contents(base_path('README.md')),
        'docs/architecture.md' => file_get_contents(base_path('docs/architecture.md')),
    ];
}

it('replaces the default Laravel README', function () {
    expect(docs()['README.md'])
        ->toStartWith('# ShellSeek')
        ->not->toContain('Laravel is a web application framework');
});

it('references only commands and files that exist', function () {
    $readme = docs()['README.md'];
    $scripts = json_decode(file_get_contents(base_path('composer.json')), true)['scripts'];
    $phpunit = file_get_contents(base_path('phpunit.xml'));

    expect($readme)->toContain('composer run serve-local')
        ->and($scripts)->toHaveKey('serve-local')
        ->and($readme)->toContain('--group=ollama')
        ->and($phpunit)->toContain('<group>ollama</group>')
        ->and($readme)->toContain('docs/architecture.md');

    foreach (['config/scope_enforcer.php', 'config/llm.php', 'config/tools.php', 'config/execution.php',
        'tests/Fixtures/fake-tool.php', 'tests/TestCase.php', 'config/auth_app.php', 'config/history.php',
        'tests/Feature/Auth/RouteProtectionTest.php'] as $path) {
        expect(file_exists(base_path($path)))->toBeTrue("{$path} is documented but missing");
    }
});

it('names classes that exist', function () {
    foreach ([
        App\Http\Controllers\ChatController::class,
        App\Services\Recon\ReconPipeline::class,
        App\Services\Llm\LlmService::class,
        App\Services\Command\CommandParsingService::class,
        App\Services\ScopeEnforcer\ScopeEnforcerClient::class,
        App\Services\Execution\ExecutionGate::class,
        App\Services\Execution\SignatureVerifier::class,
        App\Services\Execution\UnavailableSignatureVerifier::class,
        App\Services\Execution\ExecutionService::class,
        App\Services\Execution\SymfonyProcessRunner::class,
        App\Services\History\HistoryLogService::class,
        App\Services\Status\SystemStatusService::class,
        App\Services\Recon\WarningApprovalPolicy::class,
        App\Http\Controllers\Auth\LoginController::class,
        App\Http\Controllers\Auth\RegisterController::class,
        App\Http\Requests\Auth\LoginRequest::class,
        App\Http\Requests\Auth\RegisterRequest::class,
        App\Services\Auth\UserRegistrar::class,
        App\Services\Auth\AccountStatus::class,
        App\Console\Commands\ActivateUser::class,
        App\Console\Commands\DeactivateUser::class,
        App\Console\Commands\ListUsers::class,
        App\Http\Middleware\EnsureUserIsActive::class,
        App\Policies\ActivityLogPolicy::class,
    ] as $class) {
        $short = class_basename($class);
        expect(class_exists($class) || interface_exists($class))->toBeTrue("{$class} missing")
            ->and(docs()['docs/architecture.md'])->toContain($short);
    }
});

it('documents every activity_logs column', function () {
    $columns = Schema::getColumnListing('activity_logs');
    expect(count($columns))->toBeGreaterThan(30);

    foreach (array_diff($columns, ['created_at', 'updated_at']) as $column) {
        expect(docs()['docs/architecture.md'])->toContain('`'.$column.'`');
    }
});

it('documents the account columns and the public routes', function () {
    $arch = docs()['docs/architecture.md'];
    foreach (['username', 'is_active'] as $column) {
        expect(Schema::hasColumn('users', $column))->toBeTrue()
            ->and($arch)->toContain('`'.$column.'`');
    }
    expect($arch)->toContain('`/login`, `/register`')->toContain('`/up`')
        ->and(config('filesystems.disks.local.serve'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'role'))->toBeFalse()
        ->and(docs()['README.md'])->toContain('shellseek:user-activate')->toContain('no roles and no admin pages');
    foreach (['shellseek:users', 'shellseek:user-activate', 'shellseek:user-deactivate'] as $command) {
        expect(array_key_exists($command, Illuminate\Support\Facades\Artisan::all()))->toBeTrue("{$command} is documented but missing");
    }
});

it('states the current limitations honestly', function () {
    expect(docs()['README.md'])
        ->toContain('Execution is disabled')
        ->toContain('WARNING is always held')
        ->toContain('still being')
        ->not->toContain('no login')
        ->toContain('REGISTRATION_ENABLED')
        ->toContain('does **not** replace the Scope Enforcer')
        ->toContain('not to the machine')
        ->toContain('There are no built-in accounts or default credentials')
        ->toContain('Laravel 12');
});

it('contains no secret values or pipe-to-shell instructions', function () {
    foreach (docs() as $name => $text) {
        expect($text)
            ->not->toMatch('/^\s*(SCOPE_ENFORCER_TOKEN|SCOPE_ENFORCER_SIGNING_SECRET|API_SECRET|SIGNING_SECRET)\s*=/m')
            ->not->toMatch('/Bearer\s+(?!<token>|<API_SECRET>)[A-Za-z0-9._~+\/-]{16,}/')
            ->not->toMatch('/(curl|wget)[^\n]*\|\s*(ba|z)?sh\b/');
    }
});

it('keeps the real token out of the documentation', function () {
    $token = app(SecretFileReader::class)->read('/home/dio/.se_token');

    foreach (docs() as $name => $text) {
        expect(str_contains($text, $token->reveal()))->toBeFalse("real token found in {$name}");
    }
})->skip(fn () => ! is_readable('/home/dio/.se_token'), 'real token file not present on this machine');
