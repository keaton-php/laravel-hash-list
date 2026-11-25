<?php

declare(strict_types=1);

namespace Keaton\HashList\Enums;

enum Algorithm: string
{
    case Md5 = 'md5';
    case Sha256 = 'sha256';

    public function hash(string $value): string
    {
        return hash($this->value, $value);
    }

    /**
     * Length of the lowercase hex digest.
     */
    public function length(): int
    {
        return match ($this) {
            self::Md5 => 32,
            self::Sha256 => 64,
        };
    }

    public function isValidHash(string $hash): bool
    {
        return preg_match('/\A[0-9a-f]{'.$this->length().'}\z/', $hash) === 1;
    }
}
