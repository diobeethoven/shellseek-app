<?php

namespace App\Http\Middleware;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after "auth" on every application route. The session guard reloads
 * the user from the database on each request, so an account that was
 * deactivated on VM 1 is thrown out on its very next request. Fail-closed: anything
 * other than an active account ends the session and never reaches the
 * controller (so no LLM call, no validation, no execution).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isActiveAccount()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(403, LoginRequest::INACTIVE);
        }

        return redirect()->route('login')->withErrors(['username' => LoginRequest::INACTIVE]);
    }
}
