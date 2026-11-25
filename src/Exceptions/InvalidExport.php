<?php

declare(strict_types=1);

namespace Keaton\HashList\Exceptions;

use RuntimeException;

class InvalidExport extends RuntimeException implements HashListException
{
    /**
     * The path is left out on purpose, as for InvalidImport::unreadable().
     */
    public static function unwritable(): self
    {
        return new self('The export file cannot be opened for writing.');
    }

    public static function notWritable(): self
    {
        return new self('The export stream is not open for writing.');
    }

    public static function notAStream(): self
    {
        return new self('An export destination must be a file path or an open stream resource.');
    }

    public static function writeFailed(): self
    {
        return new self('Writing to the export destination failed part-way through.');
    }
}
