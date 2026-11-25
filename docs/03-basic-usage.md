<!-- nav-group: Basic usage -->
# Basic usage

You can use the package through the `HashList` facade, or resolve `Keaton\HashList\HashList` from the container.

## Adding and checking addresses

`add()` puts an address on the list, `has()` checks whether it is on the list, and `remove()` takes it off. `add()` returns `true` only when the entry is new. The table has a unique index, so two queue workers that add the same address at the same time still create one row.

```php
<?php

use Keaton\HashList\Facades\HashList;

HashList::add('jane@example.com');      // true
HashList::add('JANE@example.com');      // false
HashList::count();                      // 1
HashList::has('jane@example.com');      // true
HashList::remove('Jane@Example.com');   // true
HashList::has('jane@example.com');      // false
```

## Normalizing addresses

An address is normalized before it is hashed or looked up. It is trimmed and lowercased, and a display name or a `mailto:` prefix is removed. `normalize()` returns the normalized address. You'll find the exact rules in the [reference](08-reference.md).

```php
<?php

use Keaton\HashList\Facades\HashList;

$spellings = [
    "  jane@example.com\t",
    'Jane Doe <jane@example.com>',
    '"Doe, Jane" <JANE@example.com>',
    'mailto:jane@example.com?subject=Unsubscribe',
];

foreach ($spellings as $email) {
    echo HashList::normalize($email), PHP_EOL;
}
```

```text
jane@example.com
jane@example.com
jane@example.com
jane@example.com
```

## What is stored

Each row has an id, the name of the list, the hash of the normalized address and the time it was added.

```php
<?php

use Keaton\HashList\Facades\HashList;
use Keaton\HashList\Models\HashListEntry;

HashList::add('Jane@Example.com');

foreach (HashListEntry::all() as $entry) {
    echo $entry->list, ': ', $entry->hash, PHP_EOL;
}

HashList::hash('Jane@Example.com') === hash('sha256', 'jane@example.com');   // true
```

```text
default: 8c87b489ce35cf2e2f39f80e282cb2e804932a56a213983eeeb428407d43b52d
```

The hash is a plain `sha256` of the normalized address. Anyone who hashes addresses the same way gets the same hash, which is why your hashes can match a partner's.

## Working with hashes

A partner will usually send you hashes and not addresses. `addHash()` and `hasHash()` accept a hash directly, in upper or lower case. `has()` still works with an address, because it hashes the address and looks that up.

```php
<?php

use Keaton\HashList\Facades\HashList;

// A partner sent you this: the sha256 of an address you have never seen.
$fromPartner = 'CD25A6171969F2A3C6E35C7667E3908EF1BD2424241DB04411A0EEC454CA6C16';

HashList::addHash($fromPartner);                // true
HashList::hasHash(strtolower($fromPartner));    // true
HashList::has('Sam@Example.com');               // true
```

## Named lists

Every entry belongs to a named list. The default list is called `default`. `on()` returns an instance for another list. Lists don't see each other's entries.

```php
<?php

use Keaton\HashList\Facades\HashList;

HashList::on('banned')->add('spammer@example.com');

HashList::on('banned')->has('spammer@example.com');   // true
HashList::has('spammer@example.com');                 // false
HashList::on('banned')->count();                      // 1
HashList::count();                                    // 0
```

A list name is 1 to 64 lowercase letters, digits, `.`, `_`, `:` or `-`, and starts with a letter or a digit. Every database compares names like these the same way, whatever its collation.

**Next:** [Import and export](04-import-and-export.md).
