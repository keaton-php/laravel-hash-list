<?php

declare(strict_types=1);

namespace Keaton\HashList\Exceptions;

use Throwable;

/**
 * Every exception the package itself throws implements this. Database and
 * PHP errors are their own.
 */
interface HashListException extends Throwable
{
    //
}
