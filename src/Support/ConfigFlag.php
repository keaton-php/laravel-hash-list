<?php

declare(strict_types=1);

namespace Keaton\HashList\Support;

use Keaton\HashList\Exceptions\InvalidConfiguration;

/**
 * Reads an on/off config value without guessing.
 *
 * @internal
 */
class ConfigFlag
{
    /**
     * A bool, or a string or int such as "true", "false", "1", "0", "on",
     * "off", "yes", "no". A missing or null value is off, and so is an empty
     * string, which is how an unset `KEY=` line in an .env file arrives:
     * filter_var() reads '' as false, not as a failure. Anything else throws,
     * so a typo does not silently turn a feature off.
     *
     * @param  array<string, mixed>  $config
     */
    public static function read(array $config, string $key): bool
    {
        $value = $config[$key] ?? false;

        if (is_bool($value)) {
            return $value;
        }

        $flag = is_string($value) || is_int($value)
            ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        return $flag ?? throw InvalidConfiguration::notABoolean($key);
    }
}
