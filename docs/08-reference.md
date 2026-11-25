<!-- nav-group: Reference -->
# Reference

## Methods

These methods are available on the `HashList` facade and on an instance of `Keaton\HashList\HashList`.

| Method | Returns | Notes |
|---|---|---|
| `add($email)` | `bool` | `true` if the entry is new. Throws `InvalidEmail`. |
| `addHash($hash)` | `bool` | `true` if the entry is new. Throws `InvalidHash`. |
| `has($email)`, `hasHash($hash)` | `bool` | `false` for a malformed value. |
| `remove($email)`, `removeHash($hash)` | `bool` | `true` if an entry was removed. `false` for a malformed value. |
| `matches($email, $hash)` | `bool` | Compares with `hash_equals()`, without a query. `false` for a malformed value. |
| `hash($email)` | `string` | The hash of the normalized address. Throws `InvalidEmail`. |
| `normalize($email)` | `string` | The normalized address. Throws `InvalidEmail`. |
| `count()` | `int` | The number of entries in the list. |
| `clear()` | `int` | Removes every entry from the list and returns how many there were. |
| `hashes()` | `LazyCollection<int, string>` | Every hash in the list, oldest first, read in chunks. |
| `importHashes($source, header: false, strict: false)` | `ImportResult` | Throws `InvalidImport`. |
| `importEmails($source, header: false, strict: false)` | `ImportResult` | Throws `InvalidImport`. |
| `exportHashes($destination)` | `int` | The number of hashes written. Throws `InvalidExport`. |
| `on($list)` | `HashList` | An instance for another list. Throws `InvalidListName`. |
| `connection()` | `Connection` | The database connection the list uses. |
| `listName()` | `string` | The name of the list. |
| `algorithm()` | `Algorithm` | The algorithm in use. |

An `ImportResult` has `read`, `added`, `duplicates`, `invalid` and `firstInvalidLine`, which is `null` when every line was valid.

## Exceptions

All exceptions implement `Keaton\HashList\Exceptions\HashListException`.

| Exception | Thrown when |
|---|---|
| `InvalidConfiguration` | A setting is missing, has the wrong type or names an unsupported algorithm, or the chunk size is below 1. |
| `InvalidEmail` | `add()`, `hash()` or `normalize()` gets a value that isn't a usable address. |
| `InvalidHash` | `addHash()` gets a value that isn't a hex hash of the algorithm's length (32 characters for MD5, 64 for SHA-256), or the model is asked to save a hash that isn't a lowercase hex hash. |
| `InvalidListName` | A list name isn't 1 to 64 lowercase letters, digits, `.`, `_`, `:` or `-`, starting with a letter or a digit. |
| `InvalidImport` | The source can't be opened or read, isn't a path or a stream, or a strict import finds an invalid line. |
| `InvalidExport` | The destination can't be opened or written, or isn't a path or a stream. |

## Normalization rules

An address is normalized in these steps:

1. Whitespace is trimmed, including Unicode spaces.
2. A leading `mailto:` is removed, and so is everything from a `?` on.
3. `Name <user@host>` is reduced to `user@host`.
4. The address is lowercased and normalized to Unicode NFC, so an accented letter that can be typed two ways gives one address.
5. The address is rejected if it has whitespace, control characters, `<`, `>`, `,` or `;`, or if it doesn't have exactly one `@` with text on both sides.
6. With `strip_plus_tags` on, a `+tag` is removed from the local part. A local part that starts with `+` is kept.
7. A non-ASCII domain is converted to punycode.

The result is then validated. The whole address can be at most 254 bytes. The local part can be at most 64 bytes, without a leading, trailing or doubled dot and without any of `" ( ) , : [ ] \`. Non-ASCII letters are allowed.

The domain must be a host name with at least two labels. Each label is 1 to 63 letters, digits or hyphens, and doesn't start or end with a hyphen. This means `user@localhost` and `user@[192.0.2.1]` are not accepted.

## Import file format

Each line is read as CSV, with commas and double quotes, and only the first column is used. A UTF-8 byte-order mark at the start of the file is removed. Upper-case hashes, quoted fields and CRLF line endings are accepted. Each value is trimmed. A hash is then lowercased and must be hex of the algorithm's length, and an email is normalized.

Blank lines and lines that start with `#` are skipped and not counted. The one exception is `importEmails()`, where a line that is a usable address, such as `#billing@example.com`, is imported.

Line numbers count from the top of the file and include blank and comment lines. A line is a CSV record, so a quoted field that spans two lines counts once.

Rows are inserted `chunk_size` at a time with `insertOrIgnore()`. A row that is already in the list counts as a duplicate.

## The send-time guard

The guard listens to Laravel's `MessageSending` event. It only cancels a message when it removed at least one recipient and nobody is left in To, Cc or Bcc. A message without a listed recipient is not changed.

Nothing is sent under `Mail::fake()`, so the guard doesn't run there.
