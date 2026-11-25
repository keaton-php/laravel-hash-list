# Laravel Hash List

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keaton-php/laravel-hash-list.svg?style=flat-square)](https://packagist.org/packages/keaton-php/laravel-hash-list)
[![Tests](https://img.shields.io/github/actions/workflow/status/keaton-php/laravel-hash-list/run-tests.yml?branch=1.x&label=tests&style=flat-square)](https://github.com/keaton-php/laravel-hash-list/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/keaton-php/laravel-hash-list.svg?style=flat-square)](https://packagist.org/packages/keaton-php/laravel-hash-list)

Laravel Hash List keeps lists of email addresses as hashes. You can use it for a do-not-contact list, a list of banned signups, or a list you swap with a partner. Every spelling of an address matches the same entry, and the address itself is never stored.

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

## Lists from a partner

A partner will usually send you hashes, not addresses. `addHash()` takes a hash as it is, in upper or lower case, and `on()` keeps it on a list of its own.

```php
<?php

use Keaton\HashList\Facades\HashList;

// A partner sent you this: the sha256 of an address you have never seen.
$fromPartner = 'CD25A6171969F2A3C6E35C7667E3908EF1BD2424241DB04411A0EEC454CA6C16';

HashList::on('partner')->addHash($fromPartner);     // true
HashList::on('partner')->has('Sam@Example.com');    // true
HashList::has('Sam@Example.com');                   // false
```

You can check your own addresses against their list without ever seeing theirs, and they can do the same with yours.

## What else it does

- Keep several named lists in one table, like do-not-contact, banned and one for each partner.
- Import and export lists as hash files. They are streamed, so a list doesn't have to fit in memory.
- Import a file of plain addresses. Each one is hashed on the way in and is not written anywhere.
- A `NotListed` validation rule keeps a listed address out of a signup form, however it is spelled.
- An optional guard removes listed recipients from every mail your app sends.
- Use SHA-256, or MD5 when a partner's files use it.

## Installation

You can install the package via composer:

```bash
composer require keaton-php/laravel-hash-list
```

The package requires PHP 8.2 or higher with `ext-intl` and `ext-mbstring`, and Laravel 11, 12 or 13. It supports MySQL, PostgreSQL and SQLite.

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="hash_list-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="hash_list-config"
```

## Documentation

You'll find the documentation on [kevineaton.dev/docs/laravel-hash-list](https://kevineaton.dev/docs/laravel-hash-list/). The same pages are in the [docs](docs) folder of this repository:

- [Introduction](docs/index.md)
- [Installation](docs/02-installation.md)
- [Basic usage](docs/03-basic-usage.md)
- [Import and export](docs/04-import-and-export.md)
- [Validation and the guard](docs/05-validation-and-guard.md)
- [Sharing lists](docs/06-sharing-lists.md)
- [Advanced usage](docs/07-advanced-usage.md)
- [Reference](docs/08-reference.md)

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Kevin Eaton](https://github.com/keaton-php)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
