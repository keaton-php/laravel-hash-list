<!-- nav-group: Start here -->
# Introduction

Laravel Hash List keeps lists of email addresses as hashes. You can use it for a do-not-contact list, a list of banned signups, or a list you swap with a partner. Every spelling of an address matches the same entry, and the address itself is never stored.

## Before and after

Say you keep a do-not-contact list. The usual way is a table of addresses, and a trim and a lowercase before you compare:

```php
<?php

// the addresses in your blocked_emails table
$blocked = ['jane@example.com'];

$emails = [
    'Jane@Example.com',
    ' jane@example.com',
    'Jane Doe <jane@example.com>',
    'riley@example.com',
];

foreach ($emails as $email) {
    $listed = in_array(strtolower(trim($email)), $blocked, true);

    printf("%-30s %s\n", "'{$email}'", $listed ? 'skip' : 'send');
}
```

```text
'Jane@Example.com'             skip
' jane@example.com'            skip
'Jane Doe <jane@example.com>'  send
'riley@example.com'            send
```

That catches the capitals and the stray space. It misses the address with a name around it, which is how a mail header spells it, so Jane got the mail. And the list is a table full of the addresses you were asked to leave alone.

Here's the same list with the package:

```php
<?php

use Keaton\HashList\Facades\HashList;

HashList::add('jane@example.com');

$emails = [
    'Jane@Example.com',
    ' jane@example.com',
    'Jane Doe <jane@example.com>',
    'riley@example.com',
];

foreach ($emails as $email) {
    $listed = HashList::has($email);

    printf("%-30s %s\n", "'{$email}'", $listed ? 'skip' : 'send');
}
```

```text
'Jane@Example.com'             skip
' jane@example.com'            skip
'Jane Doe <jane@example.com>'  skip
'riley@example.com'            send
```

All three spellings of Jane are skipped. An address is cleaned up before it is hashed or looked up, so `add()` and `has()` always agree. The table holds only the hash, so there is no address in it to leak, and a check is one query on a unique index.

## What else it does

- Keep several named lists in one table, like do-not-contact, banned and one for each partner. See [Basic usage](03-basic-usage.md).
- Import and export lists as hash files. They are streamed, so a list doesn't have to fit in memory. See [Import and export](04-import-and-export.md).
- Import a file of plain addresses. Each one is hashed on the way in and is not written anywhere. See [Import and export](04-import-and-export.md).
- A `NotListed` validation rule keeps a listed address out of a signup form, however it is spelled. See [Validation and the guard](05-validation-and-guard.md).
- An optional guard removes listed recipients from every mail your app sends. See [Validation and the guard](05-validation-and-guard.md).
- Use SHA-256, or MD5 when a partner's files use it. See [Advanced usage](07-advanced-usage.md).

## When to use it

Use this package when you don't want to store the addresses themselves, or when you exchange lists with a partner. A partner's list usually arrives as hashes, and two organizations can match their lists without either one seeing the other's addresses.

If you only need to block a few addresses that you already have in plain text, a `blocked_emails` table with a unique index is simpler.

**Next:** [Installation](02-installation.md).
