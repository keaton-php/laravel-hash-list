<?php

declare(strict_types=1);

namespace Keaton\HashList;

/**
 * What a bulk import did. Blank lines, "#" comment lines and a skipped header
 * row are not counted. Always: read === added + duplicates + invalid.
 */
readonly class ImportResult
{
    public function __construct(
        public int $read,
        public int $added,
        public int $duplicates,
        public int $invalid,
        public ?int $firstInvalidLine = null,
    ) {
        //
    }
}
