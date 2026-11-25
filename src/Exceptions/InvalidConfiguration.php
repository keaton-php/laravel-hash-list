<?php

declare(strict_types=1);

namespace Keaton\HashList\Exceptions;

use InvalidArgumentException;

class InvalidConfiguration extends InvalidArgumentException implements HashListException
{
    public static function missingKey(string $key): self
    {
        return new self("The [hash_list.{$key}] configuration value is missing or has the wrong type.");
    }

    public static function unsupportedAlgorithm(): self
    {
        return new self('The [hash_list.algorithm] value is not supported. Use [md5] or [sha256].');
    }

    public static function notAnArray(): self
    {
        return new self('The [hash_list] configuration must be an array.');
    }

    public static function notABoolean(string $key): self
    {
        return new self("The [hash_list.{$key}] configuration value must be true or false.");
    }

    public static function notAString(string $key): self
    {
        return new self("The [hash_list.{$key}] configuration value must be a string or null.");
    }

    public static function invalidChunkSize(): self
    {
        return new self('The [hash_list.chunk_size] configuration value must be an integer of at least 1.');
    }
}
