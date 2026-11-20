<?php

namespace App\Providers;

use App\Services\Execution\ProcessRunner;
use App\Services\Execution\SignatureVerifier;
use App\Services\Execution\SymfonyProcessRunner;
use App\Services\Execution\UnavailableSignatureVerifier;
use App\Services\Recon\DisabledWarningApproval;
use App\Services\Recon\WarningApprovalPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fail-closed until stage 3: this verifier refuses every signature, so
        // nothing can execute until the HMAC verifier (from docs/signing/) is bound.
        $this->app->singleton(SignatureVerifier::class, UnavailableSignatureVerifier::class);

        // WARNING is held; human approval needs a backend change (disabled).
        $this->app->singleton(WarningApprovalPolicy::class, DisabledWarningApproval::class);

        // The only class allowed to start a process (argv array, no shell).
        $this->app->singleton(ProcessRunner::class, SymfonyProcessRunner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registration attempts per IP (config/auth_app.php).
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(
            max(1, (int) config('auth_app.register_max_attempts', 5))
        )->by('register|'.$request->ip()));
    }
}
