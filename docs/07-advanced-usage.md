<!-- nav-group: Advanced -->
# Advanced usage

## Choosing an algorithm

The default algorithm is `sha256`. Use `md5` only when you exchange files with a system that uses MD5 hashes, which many shared lists do.

The whole table uses one algorithm. Entries are stored as bare hashes, so if you change the algorithm later, the old entries stay in the table and never match again. Choose the algorithm before you add entries, or use a new table.

## Matching a partner's normalization

A hash only matches when both sides normalize an address the same way. Almost every mail system lowercases the whole address, although the email standard doesn't require it.

`strip_plus_tags` is off by default. When it is on, `jane+news@example.com` is treated as `jane@example.com`. Only turn it on if the files you exchange were built that way too.

## Strict imports

By default an invalid line is counted and the import continues. With `strict: true` an import throws `InvalidImport` at the first invalid line. The message has the line number, but not the content of the line.

A strict import doesn't roll back on its own. The chunks that were already written stay in the table. If you want all or nothing, wrap the import in a transaction. `connection()` returns the database connection the list uses, so the transaction is on the right connection even when `hash_list.connection` isn't the default.

```php
<?php

use Keaton\HashList\Exceptions\InvalidImport;
use Keaton\HashList\Facades\HashList;

// 600 good hashes, then one bad line.
$lines = array_map(fn ($i) => hash('sha256', "customer{$i}@example.net"), range(1, 600));
$lines[] = 'not-a-hash';

$file = sys_get_temp_dir().'/hashes.txt';
file_put_contents($file, implode("\n", $lines));

try {
    HashList::importHashes($file, strict: true);
} catch (InvalidImport $e) {
    echo $e->getMessage(), PHP_EOL;
}

echo HashList::count(), ' rows kept without a transaction', PHP_EOL;

HashList::clear();

try {
    HashList::connection()->transaction(fn () => HashList::importHashes($file, strict: true));
} catch (InvalidImport $e) {
    //
}

echo HashList::count(), ' rows kept inside a transaction', PHP_EOL;
```

```text
Line 601 is not a valid entry and strict mode is on.
500 rows kept without a transaction
0 rows kept inside a transaction
```

## Malformed values

`has()`, `hasHash()`, `remove()`, `removeHash()` and `matches()` return `false` for a malformed value, because it could never be on the list. `add()`, `addHash()`, `hash()` and `normalize()` throw.

All exceptions the package throws implement `HashListException`. Database errors are Laravel's own.

```php
<?php

use Keaton\HashList\Exceptions\HashListException;
use Keaton\HashList\Facades\HashList;

HashList::has('jane@@example.com');   // false
HashList::has('not an email');        // false

try {
    HashList::add('not an email');
} catch (HashListException $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
```

```text
Keaton\HashList\Exceptions\InvalidEmail: The given value is not a usable email address.
```

An exception message never contains a value you passed in, an address, a file path or a line of an import file. At most it has a line number or the name of a setting, so addresses don't end up in your logs.

## Hashes are personal data

A hash of an address can't be reversed, but anyone who can guess an address can hash the guess and look it up. Treat the table as personal data.

The package never writes a plain address to the table, an exception message or an import result. The `HashListEntry` model also refuses to save a hash that isn't a hex hash, however the model is filled or saved. Writes that skip the model, such as `HashListEntry::query()->update()`, `upsert()` or `DB::table(...)->insert()`, are not checked.

## Comparing one address with one hash

`has()` is one query on a unique index. To compare one address with one known hash without a query, use `matches()`. It uses PHP's `hash_equals()`, so the comparison takes the same time whatever the two values are.

```php
<?php

use Keaton\HashList\Facades\HashList;

$known = hash('sha256', 'jane@example.com');

HashList::matches('Jane@Example.com', $known);   // true
HashList::matches('sam@example.com', $known);    // false
```

**Next:** [Reference](08-reference.md).
