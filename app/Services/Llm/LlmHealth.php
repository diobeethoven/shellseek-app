<?php

namespace App\Services\Llm;

/**
 * Informational status of the local LLM for the System Status page.
 */
final class LlmHealth
{
    public function __construct(
        public readonly bool $reachable,
        public readonly bool $modelAvailable,
        public readonly string $model,
        public readonly string $message,
    ) {}
}
