<?php

declare(strict_types=1);

namespace Keaton\HashList\Exceptions;

use InvalidArgumentException;

class InvalidHash extends InvalidArgumentException implements HashListException
{
    public static function forAlgorithm(string $algorithm, int $length): self
    {
        return new self("The given value is not a {$length}-character hexadecimal {$algorithm} hash.");
    }

    /**
     * Names nothing from the row: a column name could be caller input too.
     */
    public static function notStorable(): self
    {
        return new self('A hash list entry can only hold an id, a list name, a hash and a created_at timestamp.');
    }

    public static function notAHash(): self
    {
        return new self('A hash list entry must hold a lowercase 32- or 64-character hexadecimal hash.');
    }
}
