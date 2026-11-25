<?php

declare(strict_types=1);

namespace Keaton\HashList;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Keaton\HashList\Enums\Algorithm;
use Keaton\HashList\Exceptions\InvalidConfiguration;
use Keaton\HashList\Exceptions\InvalidEmail;
use Keaton\HashList\Exceptions\InvalidExport;
use Keaton\HashList\Exceptions\InvalidHash;
use Keaton\HashList\Exceptions\InvalidImport;
use Keaton\HashList\Exceptions\InvalidListName;
use Keaton\HashList\Models\HashListEntry;
use Keaton\HashList\Support\ConfigFlag;
use Keaton\HashList\Support\StripByteOrderMark;

/**
 * A list of email addresses that only ever stores their hashes: people
 * who asked not to be contacted, banned addresses, any list you check against.
 *
 * Writes (add, addHash, import) validate their input and throw or count what
 * they reject. Lookups and removals (has, hasHash, matches, remove, removeHash)
 * never throw on malformed input: a value that cannot be a list member is
 * simply not on the list.
 *
 * @phpstan-consistent-constructor
 */
class HashList
{
    public function __construct(
        protected Algorithm $algorithm,
        protected EmailNormalizer $normalizer,
        protected string $list = 'default',
        protected int $chunkSize = 500,
    ) {
        if (preg_match(InvalidListName::PATTERN, $list) !== 1) {
            throw InvalidListName::invalid();
        }

        if ($chunkSize < 1) {
            throw InvalidConfiguration::invalidChunkSize();
        }
    }

    /**
     * @param  array<string, mixed>  $config  The contents of config/hash_list.php.
     */
    public static function fromConfig(array $config): static
    {
        $algorithm = $config['algorithm'] ?? null;

        if (! is_string($algorithm)) {
            throw InvalidConfiguration::missingKey('algorithm');
        }

        $resolved = Algorithm::tryFrom(strtolower(trim($algorithm)))
            ?? throw InvalidConfiguration::unsupportedAlgorithm();

        $list = $config['default_list'] ?? null;

        if (! is_string($list)) {
            throw InvalidConfiguration::missingKey('default_list');
        }

        $chunkSize = $config['chunk_size'] ?? null;

        if (! is_int($chunkSize) || $chunkSize < 1) {
            throw InvalidConfiguration::invalidChunkSize();
        }

        $stripPlusTags = ConfigFlag::read($config, 'strip_plus_tags');

        return new static($resolved, new EmailNormalizer($stripPlusTags), $list, $chunkSize);
    }

    /**
     * The same configuration, pointed at another named list.
     */
    public function on(string $list): static
    {
        return new static($this->algorithm, $this->normalizer, $list, $this->chunkSize);
    }

    /**
     * The database connection the list lives on, for wrapping an import in a
     * transaction on the right connection.
     */
    public function connection(): Connection
    {
        return (new HashListEntry)->getConnection();
    }

    public function listName(): string
    {
        return $this->list;
    }

    public function algorithm(): Algorithm
    {
        return $this->algorithm;
    }

    /**
     * @throws InvalidEmail
     */
    public function normalize(string $email): string
    {
        return $this->normalizer->normalize($email);
    }

    /**
     * Normalize, then hash. Every spelling of one mailbox gives one hash.
     *
     * @throws InvalidEmail
     */
    public function hash(string $email): string
    {
        return $this->algorithm->hash($this->normalizer->normalize($email));
    }

    /**
     * Whether an email is the one behind a known hash, compared with
     * hash_equals() so the comparison itself takes the same time whatever
     * the inputs. Not a list lookup.
     */
    public function matches(string $email, string $hash): bool
    {
        $normalized = $this->normalizer->tryNormalize($email);
        $expected = $this->cleanHash($hash);

        if ($normalized === null || $expected === null) {
            return false;
        }

        return hash_equals($expected, $this->algorithm->hash($normalized));
    }

    /**
     * @return bool True when the email was newly added, false when it was already listed.
     *
     * @throws InvalidEmail
     */
    public function add(string $email): bool
    {
        return $this->addHash($this->hash($email));
    }

    /**
     * @return bool True when the hash was newly added, false when it was already listed.
     *
     * @throws InvalidHash
     */
    public function addHash(string $hash): bool
    {
        $clean = $this->cleanHash($hash)
            ?? throw InvalidHash::forAlgorithm($this->algorithm->value, $this->algorithm->length());

        return $this->insert([$this->row($clean, now()->toDateTimeString())]) === 1;
    }

    public function has(string $email): bool
    {
        $normalized = $this->normalizer->tryNormalize($email);

        return $normalized !== null && $this->exists($this->algorithm->hash($normalized));
    }

    public function hasHash(string $hash): bool
    {
        $clean = $this->cleanHash($hash);

        return $clean !== null && $this->exists($clean);
    }

    public function remove(string $email): bool
    {
        $normalized = $this->normalizer->tryNormalize($email);

        return $normalized !== null && $this->delete($this->algorithm->hash($normalized));
    }

    public function removeHash(string $hash): bool
    {
        $clean = $this->cleanHash($hash);

        return $clean !== null && $this->delete($clean);
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * @return int How many entries were removed from this list.
     */
    public function clear(): int
    {
        return $this->query()->delete();
    }

    /**
     * Every hash in the list, oldest first, read in chunks.
     *
     * @return LazyCollection<int, string>
     */
    public function hashes(): LazyCollection
    {
        return $this->query()
            ->select(['id', 'hash'])
            ->lazyById($this->chunkSize)
            ->map(static fn (HashListEntry $entry): string => $entry->hash);
    }

    /**
     * Import a file of hashes: one per line, or a CSV whose first column is the hash.
     *
     * @param  resource|string  $source  A file path or an open readable stream.
     * @param  bool  $header  Skip the first CSV record that is not blank or a # comment.
     * @param  bool  $strict  Throw on the first invalid line instead of counting it.
     *
     * @throws InvalidImport
     */
    public function importHashes(mixed $source, bool $header = false, bool $strict = false): ImportResult
    {
        return $this->import($source, false, $header, $strict);
    }

    /**
     * Import a file of plain emails, hashing each one on the way in. The
     * emails are never written anywhere.
     *
     * @param  resource|string  $source  A file path or an open readable stream.
     * @param  bool  $header  Skip the first CSV record that is not blank or a # comment.
     * @param  bool  $strict  Throw on the first invalid line instead of counting it.
     *
     * @throws InvalidImport
     */
    public function importEmails(mixed $source, bool $header = false, bool $strict = false): ImportResult
    {
        return $this->import($source, true, $header, $strict);
    }

    /**
     * Write every hash, one per line, to a file path or an open writable stream.
     *
     * @param  resource|string  $destination
     * @return int How many hashes were written.
     *
     * @throws InvalidExport
     */
    public function exportHashes(mixed $destination): int
    {
        [$stream, $opened] = $this->openForWriting($destination);
        $written = 0;

        try {
            foreach ($this->hashes() as $hash) {
                $line = $hash."\n";

                // @ so a failed write is our InvalidExport, not a PHP warning
                if (@fwrite($stream, $line) !== strlen($line)) {
                    throw InvalidExport::writeFailed();
                }

                $written++;
            }

            // buffered bytes can still fail on their way out
            if (@fflush($stream) === false) {
                throw InvalidExport::writeFailed();
            }
        } finally {
            if ($opened) {
                @fclose($stream);
            }
        }

        return $written;
    }

    /**
     * @param  resource|string  $source
     */
    protected function import(mixed $source, bool $hashEmails, bool $header, bool $strict): ImportResult
    {
        [$stream, $opened] = $this->openForReading($source);
        $filter = StripByteOrderMark::attach($stream);

        try {
            return $this->consume($stream, $hashEmails, $header, $strict);
        } finally {
            // a stream the caller gave us is handed back without our filter
            if (is_resource($filter)) {
                stream_filter_remove($filter);
            }

            if ($opened) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  resource  $stream
     */
    protected function consume(mixed $stream, bool $hashEmails, bool $header, bool $strict): ImportResult
    {
        $now = now()->toDateTimeString();
        $batch = [];
        $line = 0;
        $valid = 0;
        $added = 0;
        $invalid = 0;
        $firstInvalidLine = null;
        $skipHeader = $header;

        while (($fields = @fgetcsv($stream, null, ',', '"', '')) !== false) {
            $line++;
            $value = $fields[0] ?? null;

            if ($value === null) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $hash = $hashEmails ? $this->tryHash($value) : $this->cleanHash($value);

            // A # line is a comment, unless it is itself a usable entry:
            // #billing@example.com is an address, and must not be dropped.
            if ($hash === null && str_starts_with($value, '#')) {
                continue;
            }

            if ($skipHeader) {
                $skipHeader = false;

                continue;
            }

            if ($hash === null) {
                if ($strict) {
                    throw InvalidImport::invalidLine($line);
                }

                $invalid++;
                $firstInvalidLine ??= $line;

                continue;
            }

            $valid++;
            $batch[] = $this->row($hash, $now);

            if (count($batch) >= $this->chunkSize) {
                $added += $this->insert($batch);
                $batch = [];
            }
        }

        // fgetcsv() also returns false on a read error; only end-of-file is the end
        if (! feof($stream)) {
            throw InvalidImport::readFailed($line);
        }

        if ($batch !== []) {
            $added += $this->insert($batch);
        }

        return new ImportResult($valid + $invalid, $added, $valid - $added, $invalid, $firstInvalidLine);
    }

    /**
     * @return array{0: resource, 1: bool} The stream and whether we opened it.
     */
    protected function openForReading(mixed $source): array
    {
        if (is_resource($source) && get_resource_type($source) === 'stream') {
            $mode = stream_get_meta_data($source)['mode'];

            if (! str_contains($mode, 'r') && ! str_contains($mode, '+')) {
                throw InvalidImport::notReadable();
            }

            return [$source, false];
        }

        if (! is_string($source)) {
            throw InvalidImport::notAStream();
        }

        $stream = is_file($source) ? @fopen($source, 'rb') : false;

        if ($stream === false) {
            throw InvalidImport::unreadable();
        }

        return [$stream, true];
    }

    /**
     * @return array{0: resource, 1: bool} The stream and whether we opened it.
     */
    protected function openForWriting(mixed $destination): array
    {
        if (is_resource($destination) && get_resource_type($destination) === 'stream') {
            if (strpbrk(stream_get_meta_data($destination)['mode'], 'waxc+') === false) {
                throw InvalidExport::notWritable();
            }

            return [$destination, false];
        }

        if (! is_string($destination)) {
            throw InvalidExport::notAStream();
        }

        $stream = @fopen($destination, 'wb');

        if ($stream === false) {
            throw InvalidExport::unwritable();
        }

        return [$stream, true];
    }

    protected function tryHash(string $email): ?string
    {
        $normalized = $this->normalizer->tryNormalize($email);

        return $normalized === null ? null : $this->algorithm->hash($normalized);
    }

    /**
     * Lowercase and validate a hex digest for the configured algorithm.
     */
    protected function cleanHash(string $hash): ?string
    {
        $clean = strtolower(trim($hash));

        return $this->algorithm->isValidHash($clean) ? $clean : null;
    }

    /**
     * @return array{list: string, hash: string, created_at: string}
     */
    protected function row(string $hash, string $createdAt): array
    {
        return ['list' => $this->list, 'hash' => $hash, 'created_at' => $createdAt];
    }

    /**
     * @param  list<array{list: string, hash: string, created_at: string}>  $rows
     * @return int How many rows were really inserted; rows already listed are skipped.
     */
    protected function insert(array $rows): int
    {
        return HashListEntry::query()->insertOrIgnore($rows);
    }

    protected function exists(string $hash): bool
    {
        return $this->query()->where('hash', $hash)->exists();
    }

    protected function delete(string $hash): bool
    {
        return $this->query()->where('hash', $hash)->delete() > 0;
    }

    /**
     * @return Builder<HashListEntry>
     */
    protected function query(): Builder
    {
        return HashListEntry::query()->where('list', $this->list);
    }
}
