<?php

use App\Support\SecretFileReader;
use App\Support\SecretUnavailableException;

const SE_ENV_KEYS = [
    'SCOPE_ENFORCER_BASE_URL',
    'SCOPE_ENFORCER_TOKEN_FILE',
    'SCOPE_ENFORCER_SIGNING_SECRET_FILE',
    'SCOPE_ENFORCER_CONNECT_TIMEOUT',
    'SCOPE_ENFORCER_TIMEOUT',
    'SCOPE_ENFORCER_CLOCK_SKEW',
];

function setTestEnv(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        return;
    }
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

function loadScopeEnforcerConfig(): array
{
    return require config_path('scope_enforcer.php');
}

beforeEach(function () {
    $this->savedEnv = [];
    foreach (SE_ENV_KEYS as $key) {
        $this->savedEnv[$key] = getenv($key) === false ? null : getenv($key);
        setTestEnv($key, null);
    }
});

afterEach(function () {
    foreach ($this->savedEnv as $key => $value) {
        setTestEnv($key, $value);
    }
});

it('provides safe defaults', function () {
    $config = loadScopeEnforcerConfig();

    expect($config)->toMatchArray([
        'base_url' => 'http://192.168.140.20:8000',
        'token_file' => '/home/dio/.se_token',
        'signing_secret_file' => '/home/dio/.se_signing_secret',
        'connect_timeout' => 3.0,
        'timeout' => 10.0,
        'requested_by' => 'laravel-app',
        'authorization_ttl_max' => 60,
        'clock_skew' => 5,
        'max_request_bytes' => 16384,
    ]);
});

it('is registered with the application config repository', function () {
    expect(config('scope_enforcer.requested_by'))->toBe('laravel-app')
        ->and(config('scope_enforcer.authorization_ttl_max'))->toBe(60);
});

it('can be overridden through environment variables', function () {
    setTestEnv('SCOPE_ENFORCER_BASE_URL', 'http://enforcer.test:9000/');
    setTestEnv('SCOPE_ENFORCER_TOKEN_FILE', '/tmp/token-path');
    setTestEnv('SCOPE_ENFORCER_SIGNING_SECRET_FILE', '/tmp/signing-path');
    setTestEnv('SCOPE_ENFORCER_CONNECT_TIMEOUT', '1.5');
    setTestEnv('SCOPE_ENFORCER_TIMEOUT', '7');
    setTestEnv('SCOPE_ENFORCER_CLOCK_SKEW', '2');

    expect(loadScopeEnforcerConfig())->toMatchArray([
        'base_url' => 'http://enforcer.test:9000', // trailing slash stripped
        'token_file' => '/tmp/token-path',
        'signing_secret_file' => '/tmp/signing-path',
        'connect_timeout' => 1.5,
        'timeout' => 7.0,
        'clock_skew' => 2,
    ]);
});

it('does not allow requested_by, TTL or body limit to be changed via env', function () {
    setTestEnv('SCOPE_ENFORCER_REQUESTED_BY', 'attacker');

    try {
        $config = loadScopeEnforcerConfig();
        expect($config['requested_by'])->toBe('laravel-app')
            ->and($config['authorization_ttl_max'])->toBe(60)
            ->and($config['max_request_bytes'])->toBe(16384);
    } finally {
        setTestEnv('SCOPE_ENFORCER_REQUESTED_BY', null);
    }
});

it('stores only file paths, never secret values, in the config', function () {
    $config = loadScopeEnforcerConfig();

    foreach (array_keys($config) as $key) {
        expect($key)->not->toBeIn(['token', 'api_secret', 'signing_secret', 'secret']);
    }
});

it('fails closed when the configured token file is missing', function () {
    config(['scope_enforcer.token_file' => sys_get_temp_dir().'/shellseek-missing-'.bin2hex(random_bytes(6))]);

    expect(fn () => app(SecretFileReader::class)->read(config('scope_enforcer.token_file')))
        ->toThrow(SecretUnavailableException::class);
});

it('documents only paths in .env.example', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)
        ->toContain('SCOPE_ENFORCER_TOKEN_FILE=')
        ->toContain('SCOPE_ENFORCER_SIGNING_SECRET_FILE=')
        ->not->toMatch('/^\s*(SCOPE_ENFORCER_TOKEN|SCOPE_ENFORCER_SIGNING_SECRET|API_SECRET|SIGNING_SECRET)\s*=/m');
});

it('keeps the real token out of .env.example', function () {
    // Reads the real token through code only; the value is never printed,
    // the assertion uses a custom message so a failure cannot echo it.
    $token = app(SecretFileReader::class)->read('/home/dio/.se_token');
    $example = file_get_contents(base_path('.env.example'));

    expect(str_contains($example, $token->reveal()))->toBeFalse('real token found in .env.example');
})->skip(fn () => ! is_readable('/home/dio/.se_token'), 'real token file not present on this machine');
