<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Auth\UserRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
| Accounts: username login, no roles, is_active, activity_logs.user_id.
| Passwords below are throwaway test values, not credentials of any system.
*/

function regData(string $username): array
{
    return ['name' => 'Test '.$username, 'username' => $username, 'password' => 'test-pass-'.bin2hex(random_bytes(4)).'9'];
}

it('uses username and is_active, with no email and no role columns', function () {
    expect(Schema::hasColumns('users', ['username', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumn('users', 'email'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'role'))->toBeFalse()
        ->and(Schema::hasColumn('activity_logs', 'user_id'))->toBeTrue();
});

it('creates every registered account inactive, including the first', function () {
    $registrar = app(UserRegistrar::class);

    foreach (['first', 'second', 'third'] as $name) {
        expect($registrar->register(regData($name))->fresh())
            ->is_active->toBeFalse()
            ->and($registrar->register(regData($name.'-x'))->fresh()->isActiveAccount())->toBeFalse();
    }
    expect(User::where('is_active', true)->count())->toBe(0);
});

it('ignores is_active from user input', function () {
    $sneaky = app(UserRegistrar::class)->register(regData('sneaky') + ['is_active' => true])->fresh();
    expect($sneaky->is_active)->toBeFalse();

    $direct = User::create(regData('direct') + ['is_active' => true])->fresh();
    expect($direct->is_active)->toBeFalse();

    expect((new User)->fill(['is_active' => true, 'role' => 'admin'])->getAttributes())->toBe([]);
});

it('enforces unique usernames at database level', function () {
    User::create(regData('dup'));

    expect(fn () => User::create(regData('dup')))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('stores only a password hash', function () {
    $data = regData('hash');
    $user = app(UserRegistrar::class)->register($data)->fresh();

    expect($user->getAttributes()['password'])->not->toBe($data['password'])
        ->and(Hash::check($data['password'], $user->password))->toBeTrue()
        ->and($user->toArray())->not->toHaveKey('password');
});

it('links activity logs to a user, keeps old rows at null and keeps logs when a user is deleted', function () {
    $user = User::factory()->create();

    $legacy = ActivityLog::create(['instruction' => 'legacy', 'stage' => 'llm', 'outcome' => 'in_progress']);
    $owned = ownedBy(ActivityLog::create(['instruction' => 'owned', 'stage' => 'llm', 'outcome' => 'in_progress']), $user);

    expect($legacy->fresh()->user_id)->toBeNull()
        ->and($owned->fresh()->user->is($user))->toBeTrue()
        ->and($user->activityLogs()->pluck('id')->all())->toBe([$owned->id]);

    $user->delete();
    expect($owned->fresh())->not->toBeNull()->user_id->toBeNull();
});

it('does not mass-assign activity_logs.user_id', function () {
    $user = User::factory()->create();
    $log = ActivityLog::create(['instruction' => 'x', 'stage' => 'llm', 'outcome' => 'in_progress', 'user_id' => $user->id]);

    expect($log->fresh()->user_id)->toBeNull();
});
