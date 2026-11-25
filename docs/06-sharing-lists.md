<!-- nav-group: Advanced -->
# Sharing lists

This page walks through an example. You and a partner, a museum, are running a joint open day, and each of you invites your own contacts. You both keep a do-not-contact list. Nobody on the museum's list should hear from you, and nobody on yours should hear from them, but neither of you can give the other a list of addresses.

Every night the museum sends you its list as a file of MD5 hashes, in upper case under a header line. You send your list back as hashes too.

## Replacing the partner's list

This class replaces the partner's list with the new file, inside a transaction on the list's own connection. The import is strict, and an empty file throws. An invalid or empty file rolls back, and last night's list stays in place.

```php
<?php

use Keaton\HashList\Facades\HashList;
use Keaton\HashList\ImportResult;

class ImportPartnerList
{
    /**
     * Replace a partner's do-not-contact list with tonight's file. A bad
     * line or an empty file throws, and the transaction keeps last night's
     * list.
     *
     * @param  resource|string  $file
     */
    public function __invoke(string $partner, mixed $file): ImportResult
    {
        $list = HashList::on("partner-{$partner}");

        return $list->connection()->transaction(function () use ($list, $file) {
            $list->clear();

            $result = $list->importHashes($file, header: true, strict: true);

            if ($result->added === 0) {
                throw new RuntimeException("Tonight's file is empty.");
            }

            return $result;
        });
    }
}
```

The import can't tell that a file of valid hashes was cut short. If that matters to you, agree on a count or a checksum with the partner and check it inside the transaction.

## Running the exchange

Tonight's file from the museum has four hashes. The code skips anyone on the museum's list. Your own do-not-contact list is the default list, and it is left to the guard.

```php
<?php

use Illuminate\Support\Facades\Mail;
use Keaton\HashList\Exceptions\InvalidImport;
use Keaton\HashList\Facades\HashList;
use Keaton\HashList\Models\HashListEntry;

config([
    'hash_list.algorithm' => 'md5',   // the format you and the museum agreed on
    'hash_list.guard' => true,
]);

HashList::add('max@example.com');    // both asked you not to contact them
HashList::add('noor@example.com');

// Tonight's file from the museum. The first hash is lee@example.com, which you cannot tell by looking.
$file = sys_get_temp_dir().'/museum-do-not-contact.csv';
file_put_contents($file, <<<'CSV'
EMAIL_MD5
E5B9BA17F4A98545711EF895A056FCE3
25FBD072CA80D0CB748A2D5E01BF89F0
10E8AB781D70F8B7C41BD521E17A2D07
D3F4335F70BB9F86914E37EB321F2215
CSV);

$result = app(ImportPartnerList::class)('museum', $file);

printf("Imported %d do-not-contact hashes from the museum\n\n", $result->added);

foreach (['Lee@Example.com', 'max@example.com', 'riley@example.com'] as $email) {
    if (HashList::on('partner-museum')->has($email)) {
        $outcome = "skipped, on the museum's do-not-contact list";
    } elseif (Mail::raw('You are invited to our joint open day.', fn ($mail) => $mail->to($email)->subject('A joint event'))) {
        $outcome = 'sent';
    } else {
        $outcome = 'stopped by the guard, asked us not to contact them';
    }

    printf("%-18s %s\n", $email, $outcome);
}

printf("\nStored: %d hashes, %d email addresses\n", HashListEntry::count(), HashListEntry::where('hash', 'like', '%@%')->count());

// Your own list goes back to the museum as a file of hashes.
printf("Sent the museum %d hashes of our own\n\n", HashList::exportHashes(sys_get_temp_dir().'/our-do-not-contact.txt'));

// The next night, their export breaks off part-way through a line.
$broken = sys_get_temp_dir().'/museum-broken.csv';
file_put_contents($broken, "EMAIL_MD5\nE5B9BA17F4A98545711EF895A056FCE3\nD41D8CD98F00\n");

try {
    app(ImportPartnerList::class)('museum', $broken);
} catch (InvalidImport $e) {
    echo 'Rejected the next file: ', $e->getMessage(), PHP_EOL;
}

printf("Still on file for the museum: %d hashes\n", HashList::on('partner-museum')->count());
```

```text
Imported 4 do-not-contact hashes from the museum

Lee@Example.com    skipped, on the museum's do-not-contact list
max@example.com    stopped by the guard, asked us not to contact them
riley@example.com  sent

Stored: 6 hashes, 0 email addresses
Sent the museum 2 hashes of our own

Rejected the next file: Line 3 is not a valid entry and strict mode is on.
Still on file for the museum: 4 hashes
```

Lee is on the museum's list under a different spelling, so Lee wasn't contacted. Max is on your own list, and the guard stopped that message. The table holds six hashes and no email addresses. The next night's file was broken, so it was rejected, and the four hashes from the night before are still there.

A long file is handled the same way. An import reads its source one line at a time and inserts 500 rows per query by default. An export reads the table in chunks of the same size.

**Next:** [Advanced usage](07-advanced-usage.md).
