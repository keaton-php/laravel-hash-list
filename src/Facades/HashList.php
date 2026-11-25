<?php

declare(strict_types=1);

namespace Keaton\HashList\Facades;

use Illuminate\Support\Facades\Facade;
use Keaton\HashList\Enums\Algorithm;
use Keaton\HashList\ImportResult;

/**
 * @method static \Keaton\HashList\HashList on(string $list)
 * @method static \Illuminate\Database\Connection connection()
 * @method static string listName()
 * @method static Algorithm algorithm()
 * @method static string normalize(string $email)
 * @method static string hash(string $email)
 * @method static bool matches(string $email, string $hash)
 * @method static bool add(string $email)
 * @method static bool addHash(string $hash)
 * @method static bool has(string $email)
 * @method static bool hasHash(string $hash)
 * @method static bool remove(string $email)
 * @method static bool removeHash(string $hash)
 * @method static int count()
 * @method static int clear()
 * @method static \Illuminate\Support\LazyCollection<int, string> hashes()
 * @method static ImportResult importHashes(mixed $source, bool $header = false, bool $strict = false)
 * @method static ImportResult importEmails(mixed $source, bool $header = false, bool $strict = false)
 * @method static int exportHashes(mixed $destination)
 *
 * @see \Keaton\HashList\HashList
 */
class HashList extends Facade
{
    /**
     * Resolve a fresh list on every call, so the facade always uses the
     * current config, exactly like app(HashList::class).
     *
     * @var bool
     */
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return \Keaton\HashList\HashList::class;
    }
}
