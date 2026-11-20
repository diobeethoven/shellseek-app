<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\UserRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Registration. Every new account is inactive and is not signed in; it is
 * activated on VM 1 (`php artisan shellseek:user-activate <username>`).
 * REGISTRATION_ENABLED=false makes both routes answer 404.
 */
class RegisterController extends Controller
{
    public function create(): View
    {
        self::ensureEnabled();

        return view('auth.register');
    }

    public function store(RegisterRequest $request, UserRegistrar $registrar): RedirectResponse
    {
        self::ensureEnabled();

        $user = $registrar->register($request->safe()->only(['name', 'username', 'password']));

        return redirect()->route('login')
            ->with('status', 'Account "'.$user->username.'" created. '.LoginRequest::INACTIVE);
    }

    private static function ensureEnabled(): void
    {
        abort_unless((bool) config('auth_app.registration_enabled'), 404);
    }
}
