<?php

namespace App\Services\Command;

use App\Services\ScopeEnforcer\ValidationRequest;

/**
 * Either a ValidationRequest ready for the Scope Enforcer, or a failure.
 * A request_id is assigned in both cases so failures can be recorded in
 * the history under their own id.
 */
final class ParseResult
{
    private function __construct(
        public readonly string $requestId,
        public readonly ?ValidationRequest $request,
        public readonly ?ParseFailure $failure,
        public readonly ?string $detail,
        public readonly string $rawExcerpt,
    ) {}

    public static function success(ValidationRequest $request, string $rawExcerpt): self
    {
        return new self($request->requestId, $request, null, null, $rawExcerpt);
    }

    public static function failure(string $requestId, ParseFailure $failure, ?string $detail, string $rawExcerpt): self
    {
        return new self($requestId, null, $failure, $detail, $rawExcerpt);
    }

    public function isSuccess(): bool
    {
        return $this->request !== null;
    }

    public function message(): ?string
    {
        if ($this->failure === null) {
            return null;
        }

        return $this->detail === null
            ? $this->failure->message()
            : $this->failure->message().' ('.$this->detail.')';
    }
}
