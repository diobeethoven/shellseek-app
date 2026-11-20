<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * Creates accounts from the registration form. Every new account is
 * inactive – including the very first – until someone with a shell on VM 1
 * runs `php artisan shellseek:user-activate <username>`. So nobody who can
 * reach /register can use the scanners on their own.
 *
 * is_active is decided here on the server and never taken from user input
 * (it is not in User::$fillable).
 */
class UserRegistrar
{
    /**
     * @param  array{name: string, username: string, password: string}  $data
     */
    public function register(array $data): User
    {
        $user = new User([
            'name' => $data['name'],
            'username' => $data['username'],
            'password' => $data['password'], // hashed by the "hashed" cast
        ]);
        $user->forceFill(['is_active' => false])->save();

        return $user;
    }
}
