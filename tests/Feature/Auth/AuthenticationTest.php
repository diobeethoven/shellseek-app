<?php

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/*
| Stage C: registration, login, logout. Passwords are throwaway test values.
*/

const AUTH_PW = 'lab-test-pass-2026';

function regForm(array $o = []): array
{
    return array_replace([
        'name' => 'Lab User',
        'username' => 'labuser',
        'password' => AUTH_PW,
        'password_confirmation' => AUTH_PW,
    ], $o);
}

function activeUser(array $o = []): User
{
    return User::factory()->create(array_replace(['username' => 'analyst', 'password' => AUTH_PW], $o));
}

beforeEach(function () {
    config(['auth_app.registration_enabled' => true]);
    RateLimiter::clear('register|127.0.0.1');
});

// ------------------------------------------------------------- registration

it('shows the login and registration pages to guests', function () {
    $this->get('/login')->assertOk()->assertSee('Sign in')->assertSee('name="_token"', false)
        ->assertSee(route('register'))->assertDontSee('remember');
    $this->get('/register')->assertOk()->assertSee('Create account')->assertSee('name="_token"', false);
});

it('registers every account inactive and does not sign it in, including the first', function () {
    $this->post('/register', regForm(['username' => ' LabUser ']))->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Account "labuser" created. '.LoginRequest::INACTIVE);

    $user = User::sole();
    expect($user->username)->toBe('labuser')->and($user->is_active)->toBeFalse();
    $this->assertGuest();

    $this->post('/register', regForm(['username' => 'second']))->assertRedirect(route('login'));
    expect(User::where('is_active', true)->count())->toBe(0);
    $this->assertGuest();
});

it('enforces the password policy', function (string $password, string $message) {
    $this->from('/register')->post('/register', regForm(['password' => $password, 'password_confirmation' => $password]))
        ->assertRedirect('/register')
        ->assertSessionHasErrors(['password' => $message]);
    expect(User::count())->toBe(0);
})->with([
    'too short' => ['abc12345678', 'Password must be at least 12 characters.'],
    'no number' => ['abcdefghijklmn', 'Password must contain at least one number.'],
    'no letter' => ['123456789012345', 'Password must contain at least one letter.'],
    'too long' => [str_repeat('a1', 37), 'Password may not be longer than 72 characters.'],
]);

it('requires a matching confirmation, a valid username and a name', function () {
    config(['auth_app.register_max_attempts' => 100]); // many posts in one test; throttling is tested separately
    $this->post('/register', regForm(['password_confirmation' => 'other-pass-2026x']))
        ->assertSessionHasErrors(['password' => 'Password confirmation does not match.']);
    $this->post('/register', regForm(['username' => 'ab']))
        ->assertSessionHasErrors(['username' => 'Username must be at least 3 characters.']);
    $this->post('/register', regForm(['username' => str_repeat('a', 33)]))
        ->assertSessionHasErrors(['username' => 'Username may not be longer than 32 characters.']);
    $this->post('/register', regForm(['username' => 'bad name!']))
        ->assertSessionHasErrors(['username' => 'Username may only contain lowercase letters, numbers, dots, underscores and hyphens.']);
    $this->post('/register', regForm(['username' => '']))
        ->assertSessionHasErrors(['username' => 'Username is required.']);
    $this->post('/register', regForm(['name' => '']))
        ->assertSessionHasErrors(['name' => 'Name is required.']);
    expect(User::count())->toBe(0);
});

it('rejects a duplicate username (case-insensitive)', function () {
    activeUser(['username' => 'labuser']);

    $this->post('/register', regForm(['username' => 'LABUSER']))
        ->assertSessionHasErrors(['username' => 'This username is already taken.']);
    expect(User::count())->toBe(1);
});

it('rejects unknown fields such as role, is_active and email', function (array $extra) {
    $this->post('/register', regForm($extra))
        ->assertSessionHasErrors(['form' => 'The form contains unknown fields.']);
    expect(User::count())->toBe(0);
})->with([
    'role' => [['role' => 'admin']],
    'is_active' => [['is_active' => '1']],
    'email' => [['email' => 'x@lab.test']],
]);

it('never flashes the password back to the form', function () {
    $this->post('/register', regForm(['username' => 'x']))->assertSessionHasErrors('username');

    expect(session()->getOldInput())->toHaveKey('username')
        ->not->toHaveKey('password')->not->toHaveKey('password_confirmation');
});

it('throttles registration attempts per IP', function () {
    config(['auth_app.register_max_attempts' => 5]);
    foreach (range(1, 5) as $i) {
        $this->post('/register', regForm(['username' => 'x']))->assertStatus(302);
    }
    $this->post('/register', regForm())->assertStatus(429);
    expect(User::count())->toBe(0);
});

it('answers 404 for registration when it is disabled', function () {
    config(['auth_app.registration_enabled' => false]);

    $this->get('/register')->assertNotFound();
    $this->post('/register', regForm())->assertNotFound();
    $this->get('/login')->assertOk()->assertDontSee(route('register'));
    expect(User::count())->toBe(0);
});

// ------------------------------------------------------------------- login

it('signs in an active user with a new session id', function () {
    $user = activeUser();
    $fixed = str_repeat('a', 40);

    // a failed attempt keeps the attacker-chosen session id ...
    $this->withCookie(config('session.cookie'), $fixed)
        ->post('/login', ['username' => 'analyst', 'password' => 'wrong-pass-2026x']);
    expect(session()->getId())->toBe($fixed);

    // ... a successful login replaces it (no session fixation)
    $this->withCookie(config('session.cookie'), $fixed)
        ->post('/login', ['username' => 'ANALYST', 'password' => AUTH_PW])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($fixed);
});

it('uses the same message for an unknown username and a wrong password', function () {
    activeUser();

    $this->post('/login', ['username' => 'nobody', 'password' => AUTH_PW])
        ->assertSessionHasErrors(['username' => LoginRequest::INVALID]);
    $this->post('/login', ['username' => 'analyst', 'password' => 'wrong-pass-2026x'])
        ->assertSessionHasErrors(['username' => LoginRequest::INVALID]);
    $this->assertGuest();
    expect(LoginRequest::INVALID)->toBe('Invalid username or password.');
});

it('refuses inactive accounts with a clear message', function () {
    activeUser(['is_active' => false]);

    $this->post('/login', ['username' => 'analyst', 'password' => AUTH_PW])
        ->assertSessionHasErrors(['username' => LoginRequest::INACTIVE]);
    $this->assertGuest();
});

it('throttles login after 5 attempts per username and IP, even with the right password', function () {
    activeUser();
    foreach (range(1, 5) as $i) {
        $this->post('/login', ['username' => 'analyst', 'password' => 'wrong-pass-2026x'])
            ->assertSessionHasErrors(['username' => LoginRequest::INVALID]);
    }

    $this->post('/login', ['username' => 'analyst', 'password' => AUTH_PW])
        ->assertSessionHasErrors('username');
    expect(session('errors')->first('username'))->toStartWith('Too many sign-in attempts.');
    $this->assertGuest();

    // another username from the same IP is not locked out
    activeUser(['username' => 'other']);
    $this->post('/login', ['username' => 'other', 'password' => AUTH_PW])->assertRedirect(route('dashboard'));
});

it('rejects unknown login fields', function () {
    activeUser();
    $this->post('/login', ['username' => 'analyst', 'password' => AUTH_PW, 'remember' => 'on'])
        ->assertSessionHasErrors('form');
    $this->assertGuest();
});

it('redirects signed-in users away from the guest pages', function () {
    $this->actingAs(activeUser());
    $this->get('/login')->assertRedirect();
    $this->get('/register')->assertRedirect();
});

// ------------------------------------------------------------------ logout

it('logs out only via POST and invalidates the session', function () {
    $user = activeUser();
    $this->post('/login', ['username' => 'analyst', 'password' => AUTH_PW]);
    $this->assertAuthenticatedAs($user);
    $token = session()->token();

    $this->get('/logout')->assertStatus(405);
    $this->assertAuthenticatedAs($user);

    $this->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
    expect(session()->token())->not->toBe($token);
});

it('never writes passwords to the application log', function () {
    $written = [];
    Log::listen(function ($event) use (&$written) {
        $written[] = $event->message.json_encode($event->context);
    });

    activeUser();
    $this->post('/register', regForm(['username' => 'x']));
    $this->post('/login', ['username' => 'analyst', 'password' => 'wrong-pass-2026x']);
    $this->post('/login', ['username' => 'analyst', 'password' => AUTH_PW]);

    expect(implode("\n", $written))->not->toContain(AUTH_PW)->not->toContain('wrong-pass-2026x');
});

it('shows the sign-in and registration UI and its messages in English', function () {
    config(['auth_app.register_max_attempts' => 100]);
    $indonesian = '/\b(sandi|akun|masuk|daftar|wajib|karakter|belum|sudah|tidak|harus|nama|ulangi)\b/i';

    $pages = [
        $this->get('/login')->assertSee('<html lang="en">', false)->getContent(),
        $this->get('/register')->getContent(),
    ];
    $this->post('/register', regForm(['username' => 'x', 'password' => 'short', 'password_confirmation' => 'nope']));
    $pages[] = $this->get('/register')->getContent();
    $this->post('/login', ['username' => 'nobody', 'password' => 'whatever-123456']);
    $pages[] = $this->get('/login')->assertSee('Invalid username or password.')->getContent();

    foreach ($pages as $html) {
        expect(preg_match($indonesian, strip_tags($html)))->toBe(0);
    }
});
