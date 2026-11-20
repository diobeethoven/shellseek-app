<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Local application account. There are no roles: every account is equal,
 * sees only its own activity, and must be activated on VM 1
 * (`php artisan shellseek:user-activate <username>`). Accounts control access
 * to this web app only; the Scope Enforcer still checks every command and no
 * account data is ever sent to it.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Mass assignable: only what the registration form may set. is_active is
     * deliberately NOT here; it is changed only by the artisan commands.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** Inactive accounts (not yet activated, or deactivated) cannot sign in. */
    public function isActiveAccount(): bool
    {
        return $this->is_active === true;
    }

    /** @return HasMany<ActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
