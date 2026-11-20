<?php

/*
|--------------------------------------------------------------------------
| Scope Enforcer client configuration
|--------------------------------------------------------------------------
| The Scope Enforcer (FastAPI service on VM 2) is the ONLY component that
| decides ALLOW / WARNING / DENY. Laravel is just an API client.
|
| Secrets are NEVER stored here or in .env. Only the *paths* to the secret
| files are configured; the contents are read at runtime by
| App\Support\SecretFileReader and wrapped in App\Support\Secret.
*/

return [

    // Base URL of the Scope Enforcer API (no trailing slash).
    'base_url' => rtrim((string) env('SCOPE_ENFORCER_BASE_URL', 'http://192.168.140.20:8000'), '/'),

    // File holding the Bearer token (API_SECRET). Must be mode 600.
    'token_file' => (string) env('SCOPE_ENFORCER_TOKEN_FILE', '/home/dio/.se_token'),

    // File holding the HMAC signing secret used to verify authorizations. Must be mode 600.
    'signing_secret_file' => (string) env('SCOPE_ENFORCER_SIGNING_SECRET_FILE', '/home/dio/.se_signing_secret'),

    // HTTP timeouts in seconds. Any timeout is treated as DENY (fail-closed).
    'connect_timeout' => (float) env('SCOPE_ENFORCER_CONNECT_TIMEOUT', 3),
    'timeout' => (float) env('SCOPE_ENFORCER_TIMEOUT', 10),

    // Shorter read timeout for GET /health (status pages must stay responsive).
    'health_timeout' => 3,

    // Fixed identity sent in every validation request. Not configurable on purpose.
    'requested_by' => 'laravel-app',

    // Authorizations are valid for at most 60 seconds (backend contract).
    // An authorization claiming a longer lifetime is rejected.
    'authorization_ttl_max' => 60,

    // Tolerated clock difference (seconds) between VM 1 and VM 2.
    'clock_skew' => (int) env('SCOPE_ENFORCER_CLOCK_SKEW', 5),

    // Backend rejects bodies larger than 16 KB; we refuse to send them.
    'max_request_bytes' => 16384,

];
