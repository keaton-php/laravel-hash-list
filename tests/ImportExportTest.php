<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Keaton\HashList\Exceptions\InvalidExport;
use Keaton\HashList\Exceptions\InvalidImport;
use Keaton\HashList\Tests\Support\FullDiskStream;
use Keaton\HashList\Tests\Support\TrickleStream;
use PHPUnit\Framework\Attributes\Test;

class ImportExportTest extends TestCase
{
    private function sha(string $email): string
    {
        return hash('sha256', $email);
    }

    #[Test]
    public function it_can_import_a_plain_list_of_hashes(): void
    {
        $list = $this->makeList();
        $source = $this->streamOf(implode("\n", [
            $this->sha('a@example.com'),
            $this->sha('b@example.com'),
            $this->sha('c@example.com'),
        ])."\n");

        $result = $list->importHashes($source);

        $this->assertSame(3, $result->read);
        $this->assertSame(3, $result->added);
        $this->assertSame(0, $result->duplicates);
        $this->assertSame(0, $result->invalid);
        $this->assertNull($result->firstInvalidLine);
        $this->assertSame(3, $list->count());
        $this->assertTrue($list->has('b@example.com'));
    }

    #[Test]
    public function it_leaves_a_stream_it_was_given_open(): void
    {
        $source = $this->streamOf($this->sha('a@example.com')."\n");

        $this->makeList()->importHashes($source);

        $this->assertIsResource($source);
    }

    #[Test]
    public function it_can_import_a_messy_csv_and_count_every_line_honestly(): void
    {
        $a = $this->sha('a@example.com');
        $b = $this->sha('b@example.com');
        $c = $this->sha('c@example.com');

        $source = $this->streamOf(
            "\xEF\xBB\xBFhash,note\r\n"            // 1: header behind a BOM
            ."# exported 2026-11-05\r\n"            // 2: comment
            ."\r\n"                                 // 3: blank
            .strtoupper($a).",first\r\n"           // 4: valid, upper case, extra column
            ."\"{$b}\",\"second\"\r\n"              // 5: valid, quoted
            ."{$a}\r\n"                             // 6: duplicate of line 4
            ."not-a-hash\r\n"                       // 7: invalid
            ."{$c}\r\n",                             // 8: valid
        );

        $list = $this->makeList();
        $result = $list->importHashes($source, header: true);

        $this->assertSame(5, $result->read);
        $this->assertSame(3, $result->added);
        $this->assertSame(1, $result->duplicates);
        $this->assertSame(1, $result->invalid);
        $this->assertSame(7, $result->firstInvalidLine);
        $this->assertSame($result->read, $result->added + $result->duplicates + $result->invalid);
        $this->assertSame(3, $list->count());
    }

    #[Test]
    public function it_reads_a_hash_on_the_first_line_behind_a_byte_order_mark(): void
    {
        $list = $this->makeList();

        $result = $list->importHashes($this->streamOf("\xEF\xBB\xBF".$this->sha('a@example.com')."\n"));

        $this->assertSame(1, $result->added);
        $this->assertTrue($list->has('a@example.com'));
    }

    #[Test]
    public function a_header_line_is_skipped_even_when_it_is_a_usable_hash(): void
    {
        $list = $this->makeList();

        $result = $list->importHashes(
            $this->streamOf("# from the partner\n\n".$this->sha('a@example.com')."\n".$this->sha('b@example.com')."\n"),
            header: true,
        );

        $this->assertSame(1, $result->read);
        $this->assertSame(1, $result->added);
        $this->assertFalse($list->has('a@example.com'));
        $this->assertTrue($list->has('b@example.com'));
    }

    #[Test]
    public function a_header_line_is_skipped_even_when_it_is_a_usable_address(): void
    {
        $list = $this->makeList();

        $result = $list->importEmails($this->streamOf("a@example.com\nb@example.com\n"), header: true);

        $this->assertSame(1, $result->read);
        $this->assertFalse($list->has('a@example.com'));
        $this->assertTrue($list->has('b@example.com'));
    }

    #[Test]
    public function it_does_not_skip_the_first_line_unless_asked_to(): void
    {
        $result = $this->makeList()->importHashes($this->streamOf($this->sha('a@example.com')."\n"));

        $this->assertSame(1, $result->added);
    }

    #[Test]
    public function it_counts_hashes_already_in_the_list_as_duplicates(): void
    {
        $list = $this->makeList();
        $list->add('a@example.com');

        $result = $list->importHashes($this->streamOf(
            $this->sha('a@example.com')."\n".$this->sha('b@example.com')."\n",
        ));

        $this->assertSame(1, $result->added);
        $this->assertSame(1, $result->duplicates);
        $this->assertSame(2, $list->count());
    }

    #[Test]
    public function it_imports_across_chunk_boundaries_and_counts_duplicates_between_chunks(): void
    {
        $list = $this->makeList(chunkSize: 2);
        $source = $this->streamOf(implode("\n", [
            $this->sha('a@example.com'),
            $this->sha('b@example.com'),
            $this->sha('a@example.com'),
            $this->sha('c@example.com'),
            $this->sha('d@example.com'),
        ]));

        $result = $list->importHashes($source);

        $this->assertSame(5, $result->read);
        $this->assertSame(4, $result->added);
        $this->assertSame(1, $result->duplicates);
        $this->assertSame(4, $list->count());
    }

    #[Test]
    public function it_hashes_plain_emails_on_import_and_never_stores_them(): void
    {
        $list = $this->makeList();
        $source = $this->streamOf(implode("\n", [
            'Jane@Example.com',
            ' jane@example.com ',
            'bob@example.com',
            'not an email',
            'Ann <ann@example.org>',
        ])."\n");

        $result = $list->importEmails($source);

        $this->assertSame(5, $result->read);
        $this->assertSame(3, $result->added);
        $this->assertSame(1, $result->duplicates);
        $this->assertSame(1, $result->invalid);
        $this->assertSame(4, $result->firstInvalidLine);
        $this->assertEqualsCanonicalizing(
            [$this->sha('jane@example.com'), $this->sha('bob@example.com'), $this->sha('ann@example.org')],
            $list->hashes()->values()->all(),
        );
        $this->assertStringNotContainsString('@', (string) json_encode(DB::table('hash_list_entries')->get()));
    }

    #[Test]
    public function it_can_import_a_file_by_path(): void
    {
        $path = $this->temporaryFile($this->sha('a@example.com')."\n");

        try {
            $result = $this->makeList()->importHashes($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(1, $result->added);
    }

    #[Test]
    public function it_throws_for_a_file_that_does_not_exist(): void
    {
        $this->expectException(InvalidImport::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        $this->makeList()->importHashes(sys_get_temp_dir().'/hash-list-no-such-file.txt');
    }

    #[Test]
    public function it_throws_for_a_source_that_is_neither_a_path_nor_a_stream(): void
    {
        $closed = $this->streamOf('');
        fclose($closed);

        $list = $this->makeList();

        try {
            // @phpstan-ignore argument.type (the wrong type on purpose: proves the runtime check)
            $list->importHashes(123);
            $this->fail('Expected an InvalidImport exception for an integer.');
        } catch (InvalidImport $e) {
            $this->assertStringContainsString('file path or an open stream', $e->getMessage());
        }

        $this->expectException(InvalidImport::class);
        $list->importHashes($closed);
    }

    #[Test]
    public function it_stops_at_the_first_invalid_line_in_strict_mode_without_echoing_it(): void
    {
        $list = $this->makeList();
        $source = $this->streamOf(
            $this->sha('a@example.com')."\nsecret-looking-value\n".$this->sha('b@example.com')."\n",
        );

        try {
            $list->importHashes($source, strict: true);
            $this->fail('Expected an InvalidImport exception.');
        } catch (InvalidImport $e) {
            $this->assertStringContainsString('Line 2', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }

        $this->assertSame(0, $list->count());
    }

    #[Test]
    public function it_keeps_chunks_already_written_when_strict_mode_stops_an_import(): void
    {
        $list = $this->makeList(chunkSize: 1);
        $source = $this->streamOf($this->sha('a@example.com')."\nnot-a-hash\n".$this->sha('b@example.com')."\n");

        try {
            $list->importHashes($source, strict: true);
            $this->fail('Expected an InvalidImport exception.');
        } catch (InvalidImport) {
            $this->assertSame(1, $list->count());
            $this->assertTrue($list->has('a@example.com'));
            $this->assertFalse($list->has('b@example.com'));
        }
    }

    #[Test]
    public function it_rolls_back_a_strict_import_when_wrapped_in_a_transaction(): void
    {
        $list = $this->makeList(chunkSize: 1);
        $source = $this->streamOf($this->sha('a@example.com')."\nnot-a-hash\n");

        try {
            DB::transaction(fn () => $list->importHashes($source, strict: true));
            $this->fail('Expected an InvalidImport exception.');
        } catch (InvalidImport) {
            $this->assertSame(0, $list->count());
        }
    }

    #[Test]
    public function it_can_export_every_hash_one_per_line_in_insertion_order(): void
    {
        $list = $this->makeList();
        $list->add('a@example.com');
        $list->add('b@example.com');
        $list->add('c@example.com');
        $destination = fopen('php://memory', 'w+b');
        $this->assertNotFalse($destination);

        $written = $list->exportHashes($destination);

        rewind($destination);
        $this->assertSame(3, $written);
        $this->assertSame(
            $this->sha('a@example.com')."\n".$this->sha('b@example.com')."\n".$this->sha('c@example.com')."\n",
            stream_get_contents($destination),
        );
        $this->assertSame('stream', get_resource_type($destination), 'a stream that was passed in is left open');
    }

    #[Test]
    public function it_can_export_to_a_file_path(): void
    {
        $list = $this->makeList();
        $list->add('a@example.com');
        $path = $this->temporaryFile('');

        try {
            $written = $list->exportHashes($path);
            $contents = file_get_contents($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(1, $written);
        $this->assertSame($this->sha('a@example.com')."\n", $contents);
    }

    #[Test]
    public function it_exports_nothing_for_an_empty_list(): void
    {
        $destination = fopen('php://memory', 'w+b');
        $this->assertNotFalse($destination);

        $this->assertSame(0, $this->makeList()->exportHashes($destination));

        rewind($destination);
        $this->assertSame('', stream_get_contents($destination));
    }

    #[Test]
    public function it_exports_only_its_own_list(): void
    {
        $default = $this->makeList();
        $default->add('a@example.com');
        $default->on('news')->add('b@example.com');
        $destination = fopen('php://memory', 'w+b');
        $this->assertNotFalse($destination);

        $default->exportHashes($destination);

        rewind($destination);
        $this->assertSame($this->sha('a@example.com')."\n", stream_get_contents($destination));
    }

    #[Test]
    public function it_throws_when_the_export_destination_cannot_be_written(): void
    {
        $this->expectException(InvalidExport::class);
        $this->expectExceptionMessage('cannot be opened for writing');

        $this->makeList()->exportHashes(sys_get_temp_dir().'/hash-list-no-such-dir/out.txt');
    }

    #[Test]
    public function it_throws_when_a_write_fails_part_way_through_an_export(): void
    {
        $list = $this->makeList();
        $list->add('a@example.com');
        $list->add('b@example.com');

        stream_wrapper_register('hash-list-full-disk', FullDiskStream::class);
        FullDiskStream::$capacity = 70;
        FullDiskStream::$written = '';
        FullDiskStream::$warns = false;

        try {
            $list->exportHashes('hash-list-full-disk://out');
            $this->fail('Expected an InvalidExport exception for a short write.');
        } catch (InvalidExport $e) {
            $this->assertStringContainsString('failed part-way', $e->getMessage());
            // The first line went out whole; the second was cut off after 5 bytes.
            $this->assertSame(substr($this->sha('a@example.com')."\n".$this->sha('b@example.com'), 0, 70), FullDiskStream::$written);
        } finally {
            stream_wrapper_unregister('hash-list-full-disk');
        }
    }

    #[Test]
    public function it_throws_when_the_export_destination_is_not_a_path_or_stream(): void
    {
        $this->expectException(InvalidExport::class);

        // @phpstan-ignore argument.type (the wrong type on purpose: proves the runtime check)
        $this->makeList()->exportHashes(null);
    }

    #[Test]
    public function it_round_trips_an_export_into_another_list(): void
    {
        $source = $this->makeList(chunkSize: 2);

        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $source->add("{$name}@example.com");
        }

        $file = fopen('php://memory', 'w+b');
        $this->assertNotFalse($file);
        $source->exportHashes($file);
        rewind($file);

        $copy = $source->on('copy');
        $result = $copy->importHashes($file);

        $this->assertSame(5, $result->added);
        $this->assertSame($source->hashes()->values()->all(), $copy->hashes()->values()->all());
    }

    #[Test]
    public function it_returns_a_lazy_collection_of_hashes(): void
    {
        $list = $this->makeList();
        $list->add('a@example.com');

        $this->assertInstanceOf(LazyCollection::class, $list->hashes());
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hash-list-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);

        return $path;
    }

    #[Test]
    public function it_throws_its_own_exception_when_a_failed_write_also_raises_a_warning(): void
    {
        $list = $this->makeList();
        $list->add('a@example.com');

        stream_wrapper_register('hash-list-full-disk', FullDiskStream::class);
        FullDiskStream::$capacity = 0;
        FullDiskStream::$written = '';
        FullDiskStream::$warns = true;

        try {
            $this->expectException(InvalidExport::class);

            $list->exportHashes('hash-list-full-disk://out');
        } finally {
            FullDiskStream::$warns = false;
            stream_wrapper_unregister('hash-list-full-disk');
        }
    }

    #[Test]
    public function it_refuses_a_stream_that_is_not_open_for_reading(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'hash-list-');
        $this->assertNotFalse($file);
        $stream = fopen($file, 'wb');
        $this->assertNotFalse($stream);

        try {
            $this->expectException(InvalidImport::class);
            $this->expectExceptionMessage('not open for reading');

            $this->makeList()->importHashes($stream);
        } finally {
            fclose($stream);
            unlink($file);
        }
    }

    #[Test]
    public function it_never_puts_a_path_in_an_import_or_export_message(): void
    {
        $list = $this->makeList();

        try {
            $list->importEmails('jane@example.com');
            $this->fail('Expected InvalidImport.');
        } catch (InvalidImport $e) {
            $this->assertStringNotContainsString('jane', $e->getMessage());
        }

        try {
            $list->exportHashes(sys_get_temp_dir().'/no-such-dir/jane@example.com');
            $this->fail('Expected InvalidExport.');
        } catch (InvalidExport $e) {
            $this->assertStringNotContainsString('jane', $e->getMessage());
        }
    }

    #[Test]
    public function it_imports_an_address_that_starts_with_a_hash_sign(): void
    {
        $list = $this->makeList();

        $result = $list->importEmails($this->streamOf("# opted out by hand\n#billing@example.com\n\"#sales@example.com\"\n# not an address\n"));

        $this->assertSame(2, $result->added);
        $this->assertSame(0, $result->invalid);
        $this->assertTrue($list->has('#billing@example.com'));
        $this->assertTrue($list->has('#sales@example.com'));
    }

    #[Test]
    public function it_still_skips_a_hash_sign_comment_in_a_hash_file(): void
    {
        $result = $this->makeList()->importHashes($this->streamOf("# from the partner\n".$this->sha('a@example.com')."\n"));

        $this->assertSame(1, $result->read);
        $this->assertSame(1, $result->added);
    }

    #[Test]
    public function it_refuses_an_export_stream_that_is_not_open_for_writing(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'hash-list-');
        $this->assertNotFalse($file);
        $stream = fopen($file, 'rb');
        $this->assertNotFalse($stream);

        try {
            $this->expectException(InvalidExport::class);
            $this->expectExceptionMessage('not open for writing');

            // an empty list too: the check is on the stream, not on the first write
            $this->makeList()->exportHashes($stream);
        } finally {
            fclose($stream);
            unlink($file);
        }
    }

    #[Test]
    public function it_reads_a_quoted_first_field_with_a_comma_behind_a_byte_order_mark(): void
    {
        $list = $this->makeList();

        $result = $list->importEmails($this->streamOf("\xEF\xBB\xBF\"Doe, Jane\" <jane@example.com>\nsam@example.com\n"));

        $this->assertSame(2, $result->added);
        $this->assertSame(0, $result->invalid);
        $this->assertTrue($list->has('jane@example.com'));
    }

    #[Test]
    public function it_hands_back_a_callers_stream_without_its_filter(): void
    {
        $stream = $this->streamOf("\xEF\xBB\xBF".$this->sha('a@example.com')."\n");

        $this->makeList()->importHashes($stream);

        // with our filter gone, the caller reads the mark back as it was
        rewind($stream);
        $this->assertSame("\xEF\xBB\xBF", fread($stream, 3));
        fclose($stream);
    }

    #[Test]
    public function it_strips_a_byte_order_mark_that_arrives_one_byte_at_a_time(): void
    {
        stream_wrapper_register('hash-list-trickle', TrickleStream::class);
        TrickleStream::$contents = "\xEF\xBB\xBF".$this->sha('a@example.com')."\n";

        try {
            $stream = fopen('hash-list-trickle://in', 'rb');
            $this->assertNotFalse($stream);

            $result = $this->makeList()->importHashes($stream);
            fclose($stream);

            $this->assertSame(1, $result->added);
            $this->assertSame(0, $result->invalid);
        } finally {
            stream_wrapper_unregister('hash-list-trickle');
        }
    }

    #[Test]
    public function it_throws_when_the_final_flush_fails(): void
    {
        $list = $this->makeList();
        $list->add('a@example.com');

        stream_wrapper_register('hash-list-full-disk', FullDiskStream::class);
        FullDiskStream::$capacity = 1_000;
        FullDiskStream::$written = '';
        FullDiskStream::$failsOnFlush = true;

        try {
            $this->expectException(InvalidExport::class);

            $list->exportHashes('hash-list-full-disk://out');
        } finally {
            FullDiskStream::$failsOnFlush = false;
            stream_wrapper_unregister('hash-list-full-disk');
        }
    }
}
