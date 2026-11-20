<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Email + password login (session guard, no "remember me"). Signing in only
 * grants access to this application; every command is still validated by
 * the Scope Enforcer.
 */
class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login', [
            'registrationEnabled' => (bool) config('auth_app.registration_enabled'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        Auth::guard('web')->login($user, remember: false);
        $request->session()->regenerate(); // new session id: no session fixation

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
