<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests\Support;

/**
 * A readable stream that hands over one byte per read, so a byte-order
 * mark is split across reads.
 */
class TrickleStream
{
    public static string $contents = '';

    /** @var resource|null */
    public $context;

    protected int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $byte = substr(self::$contents, $this->position, 1);
        $this->position += strlen($byte);

        return $byte;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$contents);
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
