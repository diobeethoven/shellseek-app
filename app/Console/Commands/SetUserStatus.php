<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\AccountStatus;
use Illuminate\Console\Command;

/**
 * VM 1 only: activate or deactivate an account. There is no web page for
 * this – whoever has a shell on VM 1 (the lab owner) decides who may use
 * the scanners. Deactivated users are signed out on their next request.
 */
abstract class SetUserStatus extends Command
{
    abstract protected function active(): bool;

    public function handle(AccountStatus $status): int
    {
        $username = mb_strtolower(trim((string) $this->argument('username')));
        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            $this->error("No account with username \"{$username}\". See: php artisan shellseek:users");

            return self::FAILURE;
        }

        if ($user->isActiveAccount() === $this->active()) {
            $this->info("\"{$user->username}\" is already ".($this->active() ? 'active' : 'inactive').'.');

            return self::SUCCESS;
        }

        $status->set($user, $this->active());
        $this->info("\"{$user->username}\" is now ".($this->active() ? 'active' : 'inactive').'.');

        return self::SUCCESS;
    }
}
