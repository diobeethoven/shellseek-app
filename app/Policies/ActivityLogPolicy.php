<?php

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\User;

/**
 * Activity history belongs to the account that made the request. There are
 * no roles: every account sees, archives, restores and deletes only its own
 * logs (archive/delete also need HISTORY_MANAGEMENT_ENABLED). Rows without an
 * owner (user_id null) are visible to nobody in the web UI.
 */
class ActivityLogPolicy
{
    public function view(User $user, ActivityLog $log): bool
    {
        return self::owns($user, $log);
    }

    /** Without a log: may this account manage its own logs at all (shows the buttons). */
    public function manage(User $user, ?ActivityLog $log = null): bool
    {
        return $log === null || self::owns($user, $log);
    }

    private static function owns(User $user, ActivityLog $log): bool
    {
        return $log->user_id !== null && $log->user_id === $user->id;
    }
}
