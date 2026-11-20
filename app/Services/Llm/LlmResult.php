<?php

namespace App\Services\Llm;

/**
 * Raw LLM output (untrusted text) or a failure. The content is NOT parsed
 * here; CommandParsingService validates its structure.
 */
final class LlmResult
{
    private function __construct(
        public readonly ?string $content,
        public readonly ?LlmFailure $failure,
        public readonly string $model,
        public readonly ?int $durationMs,
        public readonly ?string $detail,
    ) {}

    public static function success(string $content, string $model, int $durationMs): self
    {
        return new self($content, null, $model, $durationMs, null);
    }

    public static function failure(LlmFailure $failure, string $model, ?int $durationMs = null, ?string $detail = null): self
    {
        return new self(null, $failure, $model, $durationMs, $detail);
    }

    public function isSuccess(): bool
    {
        return $this->failure === null && $this->content !== null;
    }

    public function message(): ?string
    {
        return $this->failure?->message();
    }
}
