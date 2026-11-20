<?php

namespace App\Services\Status;

use App\Services\Execution\SignatureVerifier;
use App\Services\Execution\UnavailableSignatureVerifier;
use App\Services\Llm\LlmHealth;
use App\Services\Llm\LlmService;
use App\Services\ScopeEnforcer\HealthStatus;
use App\Services\ScopeEnforcer\ScopeEnforcerClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;

/**
 * Health overview for the Dashboard and System Status pages.
 * Purely informational: it never influences whether a command may run.
 * Cached briefly (as plain scalars, no serialized objects) so pages stay
 * fast when VM 2 is down.
 */
final class SystemStatusService
{
    public const CACHE_KEY = 'shellseek.system_status';

    public const CACHE_SECONDS = 15;

    public function __construct(
        private readonly ScopeEnforcerClient $enforcer,
        private readonly LlmService $llm,
        private readonly Container $container,
        private readonly Cache $cache,
    ) {}

    public function current(bool $fresh = false): SystemStatus
    {
        if ($fresh) {
            $this->cache->forget(self::CACHE_KEY);
        }

        $data = $this->cache->remember(self::CACHE_KEY, self::CACHE_SECONDS, function () {
            $se = $this->enforcer->health();
            $llm = $this->llm->health();

            return [
                'se' => [$se->reachable, $se->healthy, $se->httpStatus, $se->status, $se->latencyMs, $se->message],
                'llm' => [$llm->reachable, $llm->modelAvailable, $llm->model, $llm->message],
                'checked_at' => Carbon::now()->getTimestamp(),
            ];
        });

        return $this->fromCache($data);
    }

    private function fromCache(array $data): SystemStatus
    {
        return new SystemStatus(
            new HealthStatus(...$data['se']),
            new LlmHealth(...$data['llm']),
            $this->signatureVerificationAvailable(),
            (int) $data['checked_at'],
        );
    }

    public function signatureVerificationAvailable(): bool
    {
        return ! $this->container->make(SignatureVerifier::class) instanceof UnavailableSignatureVerifier;
    }
}
