<?php

declare(strict_types=1);

namespace Keaton\HashList\Exceptions;

use RuntimeException;

class InvalidImport extends RuntimeException implements HashListException
{
    /**
     * The path is left out on purpose: it is caller input, and could be an
     * address passed to the wrong argument.
     */
    public static function unreadable(): self
    {
        return new self('The import file does not exist or is not readable.');
    }

    public static function notReadable(): self
    {
        return new self('The import stream is not open for reading.');
    }

    public static function readFailed(int $line): self
    {
        return new self("Reading the import failed after line {$line}.");
    }

    public static function notAStream(): self
    {
        return new self('An import source must be a file path or an open stream resource.');
    }

    /**
     * Carries the line number only, never the line itself.
     */
    public static function invalidLine(int $line): self
    {
        return new self("Line {$line} is not a valid entry and strict mode is on.");
    }
}
