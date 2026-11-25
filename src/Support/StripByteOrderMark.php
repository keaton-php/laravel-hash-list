<?php

declare(strict_types=1);

namespace Keaton\HashList\Support;

use php_user_filter;

/**
 * A read filter that drops a UTF-8 byte-order mark from the start of a
 * stream, so the CSV parser sees a quoted first field as quoted.
 *
 * @internal
 */
class StripByteOrderMark extends php_user_filter
{
    public const NAME = 'keaton-hash-list.strip-bom';

    protected string $head = '';

    protected bool $done = false;

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $data = $bucket->data;

            if (! $this->done) {
                // hold back up to three bytes until we know whether they are a BOM
                $this->head .= $data;

                if (strlen($this->head) < 3 && ! $closing) {
                    $consumed += strlen($data);

                    continue;
                }

                $data = str_starts_with($this->head, "\xEF\xBB\xBF") ? substr($this->head, 3) : $this->head;
                $this->done = true;
            }

            $consumed += strlen($bucket->data);
            $bucket->data = $data;
            stream_bucket_append($out, $bucket);
        }

        if ($closing && ! $this->done && $this->head !== '') {
            $this->done = true;
            $bucket = stream_bucket_new($this->stream, str_starts_with($this->head, "\xEF\xBB\xBF") ? substr($this->head, 3) : $this->head);
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }

    /**
     * Attach the filter to a stream for reading; returns the handle to remove.
     *
     * @param  resource  $stream
     * @return resource|false
     */
    public static function attach($stream)
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }

        return stream_filter_append($stream, self::NAME, STREAM_FILTER_READ);
    }
}
