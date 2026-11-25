<?php

declare(strict_types=1);

namespace Keaton\HashList\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Keaton\HashList\Exceptions\InvalidConfiguration;
use Keaton\HashList\Exceptions\InvalidHash;
use Keaton\HashList\Exceptions\InvalidListName;

/**
 * One listed hash in one list. The table never holds a plain email.
 *
 * @property int $id
 * @property string $list
 * @property string $hash
 * @property Carbon|null $created_at
 */
class HashListEntry extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['list', 'hash'];

    /**
     * Checked when the value is set, for an early error.
     *
     * @return Attribute<string, string>
     */
    protected function hash(): Attribute
    {
        return Attribute::make(set: static fn (mixed $value): string => static::storableHash($value));
    }

    /**
     * @return Attribute<string, string>
     */
    protected function list(): Attribute
    {
        return Attribute::make(set: static fn (mixed $value): string => static::storableList($value));
    }

    /**
     * Checked again on the exact values Eloquent is about to write, after
     * every model event has run, so a model saved any way at all (including
     * setRawAttributes(), the *Quietly methods, or a listener that changes the
     * row) refuses a plain address. The package's own writes use
     * insertOrIgnore() and validate first; a raw query-builder insert is
     * outside the model.
     *
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        static::storableRow($this->getAttributes());

        $attributes = parent::getAttributesForInsert();

        static::storableHash($attributes['hash'] ?? null);
        static::storableList($attributes['list'] ?? null);
        static::storableRow($attributes);

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate()
    {
        // before Eloquent compares old and new values, which casts created_at
        static::storableRow($this->getAttributes());

        $dirty = parent::getDirtyForUpdate();

        if (array_key_exists('hash', $dirty)) {
            static::storableHash($dirty['hash']);
        }

        if (array_key_exists('list', $dirty)) {
            static::storableList($dirty['list']);
        }

        static::storableRow($dirty);

        return $dirty;
    }

    /**
     * The table has four columns, and only these values may go into them: an
     * integer id, and a created_at that is a database timestamp. Nothing else
     * can carry a plain address in.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected static function storableRow(array $attributes): void
    {
        foreach ($attributes as $column => $value) {
            $storable = match ($column) {
                'hash', 'list' => true,
                'id' => $value === null || is_int($value) || (is_string($value) && ctype_digit($value)),
                'created_at' => $value === null || (is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?\z/', $value) === 1),
                default => false,
            };

            if (! $storable) {
                throw InvalidHash::notStorable();
            }
        }
    }

    protected static function storableHash(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A(?:[0-9a-f]{32}|[0-9a-f]{64})\z/', $value) !== 1) {
            throw InvalidHash::notAHash();
        }

        return $value;
    }

    protected static function storableList(mixed $value): string
    {
        if (! is_string($value) || preg_match(InvalidListName::PATTERN, $value) !== 1) {
            throw InvalidListName::invalid();
        }

        return $value;
    }

    /**
     * The configured table; null or an empty string means "hash_list_entries".
     */
    public function getTable(): string
    {
        return $this->configured('table') ?? 'hash_list_entries';
    }

    /**
     * The configured connection; null or an empty string means the default.
     */
    public function getConnectionName(): ?string
    {
        return $this->configured('connection');
    }

    protected function configured(string $key): ?string
    {
        $root = config('hash_list');

        if ($root !== null && ! is_array($root)) {
            throw InvalidConfiguration::notAnArray();
        }

        $value = $root[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw InvalidConfiguration::notAString($key);
        }

        return $value === '' ? null : $value;
    }
}
