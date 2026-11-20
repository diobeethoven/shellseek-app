<?php

namespace App\Support;

use JsonSerializable;
use LogicException;
use Stringable;
use WeakMap;

/**
 * Opaque wrapper around a secret value (API token, signing secret).
 *
 * The value is NOT stored as an object property; it lives in a private
 * static WeakMap. The object therefore exposes nothing to var_export(),
 * print_r(), var_dump(), serialize(), (array) casts or reflection on
 * properties. String / JSON / debug representations are masked.
 * The only way to obtain the raw value is an explicit reveal() call.
 */
final class Secret implements JsonSerializable, Stringable
{
    public const MASK = '********';

    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        if ($value === '') {
            throw new LogicException('A secret must not be empty.');
        }

        self::$values ??= new WeakMap;
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        return self::$values[$this];
    }

    public function __toString(): string
    {
        return self::MASK;
    }

    public function jsonSerialize(): string
    {
        return self::MASK;
    }

    public function __debugInfo(): array
    {
        return ['value' => self::MASK];
    }

    public function __serialize(): array
    {
        throw new LogicException('Secrets cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Secrets cannot be unserialized.');
    }

    public function __clone()
    {
        throw new LogicException('Secrets cannot be cloned.');
    }
}
