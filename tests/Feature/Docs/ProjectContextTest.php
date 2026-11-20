<?php

use App\Support\SecretFileReader;

/*
| CLAUDE.md is loaded automatically by future sessions, so it must stay
| free of secrets and of any auto-run / install-from-internet instructions.
*/

beforeEach(function () {
    $this->context = file_get_contents(base_path('CLAUDE.md'));
});

it('exists and documents the role split and fail-closed rule', function () {
    expect($this->context)
        ->toContain('Scope Enforcer')
        ->toContain('Sole source of ALLOW/WARNING/DENY')
        ->toContain('anything other than "200 + decision ALLOW + execution_allowed true + valid authorization"')
        ->toContain('Laravel 12');
});

it('references secret files by path only', function () {
    expect($this->context)
        ->toContain('SCOPE_ENFORCER_TOKEN_FILE')
        ->toContain('SCOPE_ENFORCER_SIGNING_SECRET_FILE')
        ->not->toMatch('/^\s*(SCOPE_ENFORCER_TOKEN|SCOPE_ENFORCER_SIGNING_SECRET|API_SECRET|SIGNING_SECRET)\s*=/m')
        ->not->toMatch('/Bearer\s+(?!<API_SECRET>)[A-Za-z0-9._~+\/-]{16,}/');
});

it('contains no pipe-to-shell or auto-install instructions', function () {
    expect($this->context)
        ->not->toMatch('/(curl|wget)[^\n|]*\|\s*(ba|z)?sh\b(?!`?\)?,? and ignore)/')
        ->not->toContain('laravel/boost');

    // The only allowed mention of "curl | bash" is the prohibition itself.
    $mentions = preg_match_all('/curl \| bash/', $this->context, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [$text, $offset]) {
        expect(substr($this->context, max(0, $offset - 60), 70))->toContain('Never run scripts from the internet');
    }
    expect($mentions)->toBeLessThanOrEqual(1);
});

it('keeps the real token out of CLAUDE.md', function () {
    $token = app(SecretFileReader::class)->read('/home/dio/.se_token');

    expect(str_contains($this->context, $token->reveal()))->toBeFalse('real token found in CLAUDE.md');
})->skip(fn () => ! is_readable('/home/dio/.se_token'), 'real token file not present on this machine');
