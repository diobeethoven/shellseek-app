<?php

namespace App\Services\Command;

use Illuminate\Support\Str;

/**
 * Generates request ids owned by Laravel (never by the LLM).
 * Format: "REQ-" + ULID (30 chars), matching ^[A-Za-z0-9_-]{1,64}$.
 */
class RequestIdGenerator
{
    public function generate(): string
    {
        return 'REQ-'.Str::ulid();
    }
}
