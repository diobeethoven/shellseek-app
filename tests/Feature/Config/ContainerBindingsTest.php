<?php

use App\Services\Execution\ProcessRunner;
use App\Services\Execution\SignatureVerifier;
use App\Services\Execution\SymfonyProcessRunner;
use App\Services\Execution\UnavailableSignatureVerifier;
use App\Services\Recon\DisabledWarningApproval;
use App\Services\Recon\WarningApprovalPolicy;

/*
| Regression guard: these bindings were once lost from AppServiceProvider,
| which made POST /recon fail with HTTP 500. Until stage 3 the verifier must
| stay the always-refusing one (nothing can execute).
*/

it('binds the fail-closed production implementations', function (string $abstract, string $concrete) {
    expect(app($abstract))->toBeInstanceOf($concrete)
        ->and(app($abstract))->toBe(app($abstract)); // singleton
})->with([
    'signature verifier (stage 3 pending)' => [SignatureVerifier::class, UnavailableSignatureVerifier::class],
    'warning approval (disabled)' => [WarningApprovalPolicy::class, DisabledWarningApproval::class],
    'process runner (argv only)' => [ProcessRunner::class, SymfonyProcessRunner::class],
]);
