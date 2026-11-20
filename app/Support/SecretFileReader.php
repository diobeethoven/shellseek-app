<?php

namespace App\Support;

/**
 * Reads a secret from a file at runtime.
 *
 * Rules (any violation throws SecretUnavailableException => fail-closed):
 *  - path must be non-empty and point to a regular file
 *  - no group/other permission bits (mode 600 or 400)
 *  - readable, at most MAX_BYTES, non-empty after trimming whitespace
 */
final class SecretFileReader
{
    public const MAX_BYTES = 4096;

    public function read(string $path): Secret
    {
        if (trim($path) === '') {
            throw SecretUnavailableException::because($path, 'no path configured');
        }

        clearstatcache(true, $path);

        if (! is_file($path)) {
            throw SecretUnavailableException::because($path, 'file does not exist or is not a regular file');
        }

        $perms = @fileperms($path);
        if ($perms === false) {
            throw SecretUnavailableException::because($path, 'cannot stat file');
        }
        if (($perms & 0077) !== 0) {
            throw SecretUnavailableException::because(
                $path,
                sprintf('insecure permissions %04o (expected 0600 or 0400)', $perms & 0777)
            );
        }

        if (! is_readable($path)) {
            throw SecretUnavailableException::because($path, 'file is not readable');
        }

        $raw = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($raw === false) {
            throw SecretUnavailableException::because($path, 'read failed');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw SecretUnavailableException::because($path, 'file is too large');
        }

        $value = trim($raw);
        unset($raw);

        if ($value === '') {
            throw SecretUnavailableException::because($path, 'file is empty');
        }

        return new Secret($value);
    }
}
