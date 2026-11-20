<?php

namespace App\Console\Commands;

class ActivateUser extends SetUserStatus
{
    protected $signature = 'shellseek:user-activate {username}';

    protected $description = 'Activate a ShellSeek account so it can sign in';

    protected function active(): bool
    {
        return true;
    }
}
