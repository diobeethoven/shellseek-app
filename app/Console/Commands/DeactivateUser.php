<?php

namespace App\Console\Commands;

class DeactivateUser extends SetUserStatus
{
    protected $signature = 'shellseek:user-deactivate {username}';

    protected $description = 'Deactivate a ShellSeek account (signed out on its next request)';

    protected function active(): bool
    {
        return false;
    }
}
