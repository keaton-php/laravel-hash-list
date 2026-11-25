<?php

declare(strict_types=1);

namespace Keaton\HashList\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Keaton\HashList\HashList;

/**
 * Fails when the value is an email that is on the list.
 *
 * Anything that is not a usable email address passes: checking that is the
 * job of the "email" rule, and a value that cannot be listed is not on it.
 *
 * @phpstan-consistent-constructor
 */
class NotListed implements ValidationRule
{
    public function __construct(protected ?string $list = null)
    {
        //
    }

    /**
     * Check a named list instead of the default one.
     */
    public static function on(string $list): static
    {
        return new static($list);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $hashList = app(HashList::class);

        if ($this->list !== null) {
            $hashList = $hashList->on($this->list);
        }

        if ($hashList->has($value)) {
            $fail('The :attribute is not allowed.');
        }
    }
}
