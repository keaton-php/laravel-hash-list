<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests\Support;

/**
 * A stream that accepts the first $capacity bytes and then refuses to
 * write, like a disk that fills up part-way through an export.
 */
class FullDiskStream
{
    public static int $capacity = 0;

    public static string $written = '';

    /** Raise a PHP warning on a refused write, as a real full disk does. */
    public static bool $warns = false;

    /** Accept every write, then fail when the buffer is flushed. */
    public static bool $failsOnFlush = false;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $room = max(0, self::$capacity - strlen(self::$written));
        $accepted = substr($data, 0, $room);
        self::$written .= $accepted;

        if (self::$warns && $accepted === '') {
            trigger_error('No space left on device', E_USER_WARNING);
        }

        return strlen($accepted);
    }

    public function stream_flush(): bool
    {
        return ! self::$failsOnFlush;
    }

    public function stream_close(): void
    {
        //
    }
}
