<?php

declare(strict_types=1);

namespace Keaton\HashList\Exceptions;

use InvalidArgumentException;

class InvalidEmail extends InvalidArgumentException implements HashListException
{
    /**
     * The message deliberately never contains the offending value, so a
     * plain address cannot leak into logs through this package.
     */
    public static function malformed(): self
    {
        return new self('The given value is not a usable email address.');
    }
}
