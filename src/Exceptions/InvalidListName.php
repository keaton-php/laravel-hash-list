<?php

declare(strict_types=1);

namespace Keaton\HashList\Exceptions;

use InvalidArgumentException;

class InvalidListName extends InvalidArgumentException implements HashListException
{
    public const MAX_LENGTH = 64;

    /**
     * Lowercase letters, digits and . _ : -, starting with a letter or digit.
     * Narrow on purpose: every database compares these names the same way,
     * whatever its collation.
     */
    public const PATTERN = '/\A[a-z0-9][a-z0-9._:-]{0,63}\z/';

    public static function invalid(): self
    {
        return new self('A list name must be 1 to '.self::MAX_LENGTH.' characters of lowercase letters, digits, ".", "_", ":" or "-", starting with a letter or digit.');
    }
}
