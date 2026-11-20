<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** VM 1 only: list accounts and whether they are active. Never shows passwords. */
class ListUsers extends Command
{
    protected $signature = 'shellseek:users';

    protected $description = 'List ShellSeek accounts and their status';

    public function handle(): int
    {
        $users = User::query()->orderBy('id')->get(['id', 'name', 'username', 'is_active', 'created_at']);

        if ($users->isEmpty()) {
            $this->info('No accounts yet. Register one at /register, then activate it here.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Username', 'Name', 'Status', 'Registered'], $users->map(fn (User $u) => [
            $u->id, $u->username, $u->name, $u->is_active ? 'active' : 'inactive', $u->created_at?->format('Y-m-d H:i'),
        ])->all());

        return self::SUCCESS;
    }
}
