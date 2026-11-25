<!-- nav-group: Basic usage -->
# Import and export

Imports and exports are streamed. A file is read one line at a time and written in chunks, so a list doesn't have to fit in memory.

## Importing hashes

A hash file has one hash per line, or is a CSV file with the hash in the first column. `importHashes()` accepts a file path or an open stream, and returns an `ImportResult`.

```php
<?php

use Keaton\HashList\Facades\HashList;

$file = sys_get_temp_dir().'/partner-do-not-contact.csv';

file_put_contents($file, implode("\n", [
    'hash,added_on',
    hash('sha256', 'sam@example.com').',2026-10-01',
    strtoupper(hash('sha256', 'lee@example.com')).',2026-10-02',
    '',
    '# added by hand',
    hash('sha256', 'sam@example.com').',2026-10-03',
    'not-a-hash,2026-10-04',
]));

$result = HashList::importHashes($file, header: true);

$result->read;                       // 4
$result->added;                      // 2
$result->duplicates;                 // 1
$result->invalid;                    // 1
$result->firstInvalidLine;           // 7
HashList::has('Lee@Example.com');    // true
```

Blank lines and lines that start with `#` are skipped and not counted. With `header: true` the first line that isn't blank or a comment is skipped too. `read` is always equal to `added + duplicates + invalid`.

An invalid line is counted and doesn't stop the import, unless the import is [strict](07-advanced-usage.md). The [reference](08-reference.md) describes the file format.

For a file on S3 you can pass `Storage::disk('s3')->readStream($path)`. A stream you pass in is left open. A file the package opens itself is closed again.

## Importing plain emails

If you have a file of addresses, `importEmails()` hashes each one as it is imported. The addresses are not written to the table, to the result or to an exception message.

```php
<?php

use Keaton\HashList\Facades\HashList;

$file = sys_get_temp_dir().'/addresses.txt';
file_put_contents($file, "Jane@Example.com\nriley@example.com\nnot an email\n");

$result = HashList::importEmails($file);

$result->added;                       // 2
$result->invalid;                     // 1
HashList::has('jane@example.com');    // true
```

## Exporting

`exportHashes()` writes every hash in the list to a file path or a stream, one per line and oldest first. It returns the number of hashes it wrote. `importHashes()` can read the file back in.

If you want to process the hashes yourself, `hashes()` returns them as a `LazyCollection` that reads the table in chunks.

```php
<?php

use Keaton\HashList\Facades\HashList;

HashList::add('jane@example.com');
HashList::add('sam@example.com');

$file = sys_get_temp_dir().'/do-not-contact.txt';

HashList::exportHashes($file);   // 2

echo file_get_contents($file);
```

```text
8c87b489ce35cf2e2f39f80e282cb2e804932a56a213983eeeb428407d43b52d
cd25a6171969f2a3c6e35c7667e3908ef1bd2424241db04411a0eec454ca6c16
```

**Next:** [Validation and the guard](05-validation-and-guard.md).
