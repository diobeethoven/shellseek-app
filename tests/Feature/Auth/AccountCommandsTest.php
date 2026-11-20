<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/*
| Accounts are activated / deactivated on VM 1 with artisan, never on the web.
*/

it('lists accounts without passwords', function () {
    User::factory()->create(['username' => 'alice', 'name' => 'Alice']);
    User::factory()->inactive()->create(['username' => 'bob', 'name' => 'Bob']);

    $this->artisan('shellseek:users')
        ->expectsTable(['ID', 'Username', 'Name', 'Status', 'Registered'], User::orderBy('id')->get()->map(fn ($u) => [
            $u->id, $u->username, $u->name, $u->is_active ? 'active' : 'inactive', $u->created_at?->format('Y-m-d H:i'),
        ])->all())
        ->assertSuccessful();
});

it('says how to start when there are no accounts', function () {
    $this->artisan('shellseek:users')->expectsOutputToContain('Register one at /register')->assertSuccessful();
});

it('activates an account, which can then sign in', function () {
    $user = User::factory()->inactive()->create(['username' => 'bob', 'password' => 'lab-test-pass-2026']);

    $this->artisan('shellseek:user-activate', ['username' => 'BOB'])->expectsOutput('"bob" is now active.')->assertSuccessful();
    expect($user->fresh()->is_active)->toBeTrue();

    $this->post('/login', ['username' => 'bob', 'password' => 'lab-test-pass-2026'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user->fresh());
});

it('deactivates an account, which is signed out on its next request', function () {
    User::factory()->create(['username' => 'alice', 'password' => 'lab-test-pass-2026']);
    $this->post('/login', ['username' => 'alice', 'password' => 'lab-test-pass-2026'])->assertRedirect(route('dashboard'));
    $this->get('/status')->assertOk();

    $this->artisan('shellseek:user-deactivate', ['username' => 'alice'])->expectsOutput('"alice" is now inactive.')->assertSuccessful();

    // like a new HTTP request: the guard reloads the user from the session + database
    app('auth')->forgetGuards();
    $this->get('/recon')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('fails for an unknown username and reports no-op changes', function () {
    User::factory()->create(['username' => 'alice']);

    $this->artisan('shellseek:user-activate', ['username' => 'nobody'])->assertFailed();
    $this->artisan('shellseek:user-activate', ['username' => 'alice'])->expectsOutput('"alice" is already active.')->assertSuccessful();
});

it('logs status changes by id only', function () {
    $user = User::factory()->inactive()->create(['username' => 'bob', 'name' => 'Bob Secretname']);
    $lines = [];
    Log::listen(function ($e) use (&$lines) {
        $lines[] = $e->message;
    });

    $this->artisan('shellseek:user-activate', ['username' => 'bob']);

    expect($lines)->toContain("ShellSeek account #{$user->id} activated via artisan")
        ->and(implode("\n", $lines))->not->toContain('Bob Secretname');
});
