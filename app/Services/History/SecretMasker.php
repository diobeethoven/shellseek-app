<?php

namespace App\Services\History;

use App\Support\Secret;
use App\Support\SecretFileReader;
use App\Support\SecretUnavailableException;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Removes secrets from text before it is stored or displayed.
 *
 *  - the real API token and signing secret (read from their files at
 *    runtime; missing files are simply skipped) are replaced by ********;
 *  - "Bearer <token>" and "Authorization: <value>" patterns are masked.
 */
class SecretMasker
{
    private const MIN_SECRET_LENGTH = 8;

    /** @var list<Secret>|null */
    private ?array $secrets = null;

    public function __construct(
        private readonly SecretFileReader $reader,
        private readonly Config $config,
    ) {}

    public function mask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        foreach ($this->secrets() as $secret) {
            $text = str_replace($secret->reveal(), Secret::MASK, $text);
        }

        $text = preg_replace('/(authorization\s*[:=]\s*)[^\r\n]+/i', '$1'.Secret::MASK, $text) ?? Secret::MASK;

        return preg_replace('/\bbearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer '.Secret::MASK, $text) ?? Secret::MASK;
    }

    /**
     * Recursively masks every string inside an array (keys and values).
     */
    public function maskArray(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $masked = [];
        foreach ($data as $key => $value) {
            $key = is_string($key) ? $this->mask($key) : $key;
            $masked[$key] = match (true) {
                is_string($value) => $this->mask($value),
                is_array($value) => $this->maskArray($value),
                default => $value,
            };
        }

        return $masked;
    }

    /** @return list<Secret> */
    private function secrets(): array
    {
        if ($this->secrets !== null) {
            return $this->secrets;
        }

        $this->secrets = [];
        foreach (['token_file', 'signing_secret_file'] as $key) {
            try {
                $secret = $this->reader->read((string) $this->config->get('scope_enforcer.'.$key));
                if (strlen($secret->reveal()) >= self::MIN_SECRET_LENGTH) {
                    $this->secrets[] = $secret;
                }
            } catch (SecretUnavailableException) {
                // nothing to mask for a secret that is not present
            }
        }

        return $this->secrets;
    }
}
