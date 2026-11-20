<?php

namespace App\Support;

use RuntimeException;

/**
 * Thrown when a secret file cannot be used. Messages contain the path and
 * the reason only, never the file contents. Callers must treat this as
 * fail-closed (DENY, no execution).
 */
final class SecretUnavailableException extends RuntimeException
{
    public static function because(string $path, string $reason): self
    {
        return new self(sprintf('Secret file "%s" is unusable: %s.', $path, $reason));
    }
}
