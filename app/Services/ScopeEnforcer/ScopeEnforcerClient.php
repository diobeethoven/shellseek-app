<?php

namespace App\Services\ScopeEnforcer;

use App\Support\SecretFileReader;
use App\Support\SecretUnavailableException;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use JsonException;
use Throwable;

/**
 * HTTP client for the Scope Enforcer (VM 2).
 *
 * This class is NOT a policy engine. It sends the command exactly as given,
 * and turns the backend answer into a ValidationResult. Anything other than
 * a well-formed HTTP 200 answer becomes DENY (fail-closed). validate() and
 * health() never throw.
 *
 * Retry policy: at most one retry, and only when the TCP connection was
 * never established (DNS failure, connection refused, connect timeout).
 * A read timeout or any HTTP response is never retried, because the
 * backend may already have processed and logged the request.
 */
final class ScopeEnforcerClient
{
    public const MAX_ATTEMPTS = 2;

    public const RETRY_DELAY_MS = 250;

    public const VALIDATE_PATH = '/api/v1/validate';

    public const HEALTH_PATH = '/health';

    public const AUTH_ALGORITHM = 'HMAC-SHA256';

    private const RISK_LEVELS = ['LOW', 'MEDIUM', 'HIGH'];

    private const VALIDATION_KEYS = ['command_valid', 'target_valid', 'tool_valid', 'parameters_valid'];

    // curl error numbers
    private const CURLE_COULDNT_RESOLVE_HOST = 6;

    private const CURLE_COULDNT_CONNECT = 7;

    private const CURLE_OPERATION_TIMEDOUT = 28;

    public function __construct(
        private readonly SecretFileReader $secrets,
        private readonly Config $config,
    ) {}

    public function validate(ValidationRequest $request): ValidationResult
    {
        $requestId = $request->requestId;

        try {
            try {
                $token = $this->secrets->read((string) $this->cfg('token_file'));
            } catch (SecretUnavailableException $e) {
                return $this->failClosed($requestId, FailureCode::TokenUnavailable, $e->getMessage());
            }

            try {
                $body = json_encode(
                    $request->toPayload((string) $this->cfg('requested_by')),
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                return $this->failClosed($requestId, FailureCode::InvalidRequestEncoding);
            }

            if (strlen($body) > (int) $this->cfg('max_request_bytes')) {
                return $this->failClosed($requestId, FailureCode::RequestTooLarge, strlen($body).' bytes');
            }

            $outcome = $this->send(fn () => $this->http()
                ->withToken($token->reveal())
                ->withBody($body, 'application/json')
                ->post($this->url(self::VALIDATE_PATH)));

            if ($outcome instanceof FailureCode) {
                return $this->failClosed($requestId, $outcome);
            }

            return $this->interpret($request, $outcome);
        } catch (Throwable $e) {
            return $this->failClosed($requestId, FailureCode::Unexpected, $e::class);
        }
    }

    public function health(): HealthStatus
    {
        try {
            $started = hrtime(true);
            $outcome = $this->send(fn () => $this->http()
                ->timeout((float) $this->cfg('health_timeout'))
                ->get($this->url(self::HEALTH_PATH)));
            $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);

            if ($outcome instanceof FailureCode) {
                return new HealthStatus(false, false, null, null, null, $outcome->message());
            }

            $json = json_decode($outcome->body(), true);
            $status = is_array($json) && is_string($json['status'] ?? null) ? $json['status'] : null;
            $healthy = $outcome->status() === 200 && $status === 'ok';

            $message = $healthy
                ? 'Scope Enforcer is healthy.'
                : sprintf(
                    'Scope Enforcer is unhealthy (HTTP %d, status "%s"). Validation requests will be treated as DENY.',
                    $outcome->status(),
                    $status ?? 'unknown'
                );

            return new HealthStatus(true, $healthy, $outcome->status(), $status, $latencyMs, $message);
        } catch (Throwable) {
            return new HealthStatus(false, false, null, null, null, FailureCode::Unexpected->message());
        }
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->withoutRedirecting()
            ->connectTimeout((float) $this->cfg('connect_timeout'))
            ->timeout((float) $this->cfg('timeout'));
    }

    /**
     * @param  Closure(): Response  $call
     */
    private function send(Closure $call): Response|FailureCode
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (ConnectionException $e) {
                [$code, $neverConnected] = $this->classifyConnectionFailure($e);

                if ($neverConnected && $attempt < self::MAX_ATTEMPTS) {
                    Sleep::for(self::RETRY_DELAY_MS)->milliseconds();

                    continue;
                }

                return $code;
            }
        }
    }

    /**
     * @return array{0: FailureCode, 1: bool} [failure code, whether the TCP connection was never established]
     */
    private function classifyConnectionFailure(ConnectionException $e): array
    {
        $previous = $e->getPrevious();
        $context = $previous instanceof ConnectException ? $previous->getHandlerContext() : [];
        $errno = $context['errno'] ?? null;
        $connectTime = $context['connect_time'] ?? null;

        return match (true) {
            in_array($errno, [self::CURLE_COULDNT_RESOLVE_HOST, self::CURLE_COULDNT_CONNECT], true) => [FailureCode::Unreachable, true],
            $errno === self::CURLE_OPERATION_TIMEDOUT && is_numeric($connectTime) && (float) $connectTime === 0.0 => [FailureCode::Unreachable, true],
            $errno === self::CURLE_OPERATION_TIMEDOUT => [FailureCode::Timeout, false],
            default => [FailureCode::Unreachable, false],
        };
    }

    private function interpret(ValidationRequest $request, Response $response): ValidationResult
    {
        $id = $request->requestId;

        if ($response->status() !== 200) {
            return $this->failClosed($id, match ($response->status()) {
                401 => FailureCode::Unauthorized,
                413 => FailureCode::PayloadTooLarge,
                500 => FailureCode::ServerError,
                default => FailureCode::UnexpectedStatus,
            }, 'HTTP '.$response->status());
        }

        try {
            $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->failClosed($id, FailureCode::InvalidJson);
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            return $this->failClosed($id, FailureCode::InvalidSchema, 'body is not a JSON object');
        }

        // --- schema -----------------------------------------------------
        if (! is_string($data['request_id'] ?? null)) {
            return $this->failClosed($id, FailureCode::InvalidSchema, 'request_id');
        }
        if ($data['request_id'] !== $id) {
            return $this->failClosed($id, FailureCode::RequestIdMismatch);
        }

        $decision = is_string($data['decision'] ?? null) ? Decision::tryFrom($data['decision']) : null;
        if ($decision === null) {
            return $this->failClosed($id, FailureCode::InvalidSchema, 'decision');
        }

        if (! in_array($data['risk_level'] ?? null, self::RISK_LEVELS, true)) {
            return $this->failClosed($id, FailureCode::InvalidSchema, 'risk_level');
        }

        if (! is_bool($data['execution_allowed'] ?? null)) {
            return $this->failClosed($id, FailureCode::InvalidSchema, 'execution_allowed');
        }

        $reasons = $data['reasons'] ?? null;
        if (! is_array($reasons) || ! array_is_list($reasons)) {
            return $this->failClosed($id, FailureCode::InvalidSchema, 'reasons');
        }
        foreach ($reasons as $reason) {
            if (! is_string($reason)) {
                return $this->failClosed($id, FailureCode::InvalidSchema, 'reasons');
            }
        }

        $rawValidation = $data['validation'] ?? null;
        if (! is_array($rawValidation)) {
            return $this->failClosed($id, FailureCode::InvalidSchema, 'validation');
        }
        $validation = [];
        foreach (self::VALIDATION_KEYS as $key) {
            if (! is_bool($rawValidation[$key] ?? null)) {
                return $this->failClosed($id, FailureCode::InvalidSchema, 'validation.'.$key);
            }
            $validation[$key] = $rawValidation[$key];
        }

        if (! is_string($data['policy_version'] ?? null) || $data['policy_version'] === '') {
            return $this->failClosed($id, FailureCode::InvalidSchema, 'policy_version');
        }

        $authorization = null;
        $rawAuth = $data['authorization'] ?? null;
        if ($rawAuth !== null) {
            if (! is_array($rawAuth)
                || ! is_int($rawAuth['issued_at'] ?? null)
                || ! is_int($rawAuth['expires_at'] ?? null)
                || ! is_string($rawAuth['algorithm'] ?? null)
                || ! is_string($rawAuth['signature'] ?? null)) {
                return $this->failClosed($id, FailureCode::InvalidSchema, 'authorization');
            }
            $authorization = new Authorization(
                $rawAuth['issued_at'], $rawAuth['expires_at'], $rawAuth['algorithm'], $rawAuth['signature'],
            );
        }

        // --- consistency (contract invariants, not policy) ----------------
        if ($decision === Decision::Allow) {
            if ($data['execution_allowed'] !== true || $authorization === null) {
                return $this->failClosed($id, FailureCode::InconsistentDecision, 'ALLOW without execution_allowed/authorization');
            }
            if ($authorization->algorithm !== self::AUTH_ALGORITHM
                || preg_match('/\A[0-9a-f]{64}\z/', $authorization->signature) !== 1
                || $authorization->expiresAt <= $authorization->issuedAt) {
                return $this->failClosed($id, FailureCode::InvalidSchema, 'authorization format');
            }
        } elseif ($data['execution_allowed'] !== false || $authorization !== null) {
            return $this->failClosed($id, FailureCode::InconsistentDecision, $decision->value.' with execution_allowed/authorization');
        }

        return ValidationResult::fromBackend(
            $id,
            $decision,
            $data['risk_level'],
            $data['execution_allowed'],
            $reasons,
            $validation,
            $data['policy_version'],
            $authorization,
        );
    }

    private function failClosed(string $requestId, FailureCode $code, ?string $detail = null): ValidationResult
    {
        // Never log tokens, headers or request bodies.
        Log::warning('scope_enforcer.fail_closed', array_filter([
            'request_id' => $requestId,
            'code' => $code->value,
            'detail' => $detail,
        ], fn ($v) => $v !== null));

        return ValidationResult::failClosed($requestId, $code);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->cfg('base_url'), '/').$path;
    }

    private function cfg(string $key): mixed
    {
        return $this->config->get('scope_enforcer.'.$key);
    }
}
