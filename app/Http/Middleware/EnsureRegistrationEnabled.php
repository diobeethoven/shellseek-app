<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REGISTRATION_ENABLED=false => /register answers 404 before any validation
 * or database query runs.
 */
class EnsureRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('auth_app.registration_enabled'), 404);

        return $next($request);
    }
}
