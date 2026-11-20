<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case binding
|--------------------------------------------------------------------------
| Feature tests boot the Laravel application. Unit tests stay framework-free
| unless a specific test file opts in.
*/

pest()->extend(TestCase::class)->in('Feature');

/**
 * Sign in as an active account (default) for tests of application pages,
 * which all sit behind ['auth', 'active']. Logs are only visible to their
 * owner, so test rows should be owned by this user (see ownedBy()).
 */
function signIn(?App\Models\User $user = null): App\Models\User
{
    $user ??= App\Models\User::factory()->create();
    test()->actingAs($user);

    return $user;
}

/** Give a test row to the signed-in user (user_id is not mass-assignable). */
function ownedBy(App\Models\ActivityLog $log, ?App\Models\User $user = null): App\Models\ActivityLog
{
    $log->forceFill(['user_id' => ($user ?? auth()->user())?->id])->save();

    return $log;
}
