<?php

namespace App\Services\ScopeEnforcer;

use InvalidArgumentException;

/**
 * A command to be checked by the Scope Enforcer.
 *
 * Values are carried as-is (no "fixing", no policy checks): the backend is
 * the only component that decides whether they are acceptable.
 * `requested_by` is not part of this object; the client always sends the
 * fixed value from config.
 */
final class ValidationRequest
{
    /** @var list<string> */
    public readonly array $parameters;

    /**
     * @param  array<int, string>  $parameters
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $tool,
        public readonly string $action,
        public readonly string $target,
        array $parameters,
    ) {
        foreach ($parameters as $parameter) {
            if (! is_string($parameter)) {
                throw new InvalidArgumentException('Every parameter must be a string.');
            }
        }

        $this->parameters = array_values($parameters);
    }

    /**
     * Exact JSON body for POST /api/v1/validate (field order as in the contract).
     *
     * @return array{request_id: string, tool: string, action: string, target: string, parameters: list<string>, requested_by: string}
     */
    public function toPayload(string $requestedBy): array
    {
        return [
            'request_id' => $this->requestId,
            'tool' => $this->tool,
            'action' => $this->action,
            'target' => $this->target,
            'parameters' => $this->parameters,
            'requested_by' => $requestedBy,
        ];
    }
}
