<?php

/*
|--------------------------------------------------------------------------
| Application accounts (local access control)
|--------------------------------------------------------------------------
| Login controls who may use this web application. It does NOT replace the
| Scope Enforcer: every command of every user is still validated by VM 2.
| No user identity is ever sent to VM 2 (requested_by stays "laravel-app").
|
| There are no roles. Every registered account is inactive until it is
| activated on VM 1: `php artisan shellseek:user-activate <username>`.
| There are no built-in accounts or default credentials.
*/

return [

    // false => GET/POST /register answer 404.
    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', true),

    // Login: attempts per minute per (username + IP).
    'login_max_attempts' => 5,

    // Registration: attempts per minute per IP.
    'register_max_attempts' => 5,

];
