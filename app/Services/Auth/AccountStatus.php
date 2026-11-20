<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Activates / deactivates accounts. Used only by the artisan commands on
 * VM 1 – there is no web page for it. A deactivated user is signed out on
 * their next request (EnsureUserIsActive). Changes are logged by id only.
 */
class AccountStatus
{
    public function set(User $user, bool $active): User
    {
        $user->forceFill(['is_active' => $active])->save();
        Log::info('ShellSeek account #'.$user->id.' '.($active ? 'activated' : 'deactivated').' via artisan');

        return $user;
    }
}
