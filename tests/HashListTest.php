<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Keaton\HashList\Enums\Algorithm;
use Keaton\HashList\Exceptions\InvalidConfiguration;
use Keaton\HashList\Exceptions\InvalidEmail;
use Keaton\HashList\Exceptions\InvalidHash;
use Keaton\HashList\Exceptions\InvalidListName;
use Keaton\HashList\Facades\HashList as HashListFacade;
use Keaton\HashList\HashList;
use Keaton\HashList\Models\HashListEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class HashListTest extends TestCase
{
    private const SPELLINGS = [
        'jane.doe@example.com',
        'Jane.Doe@Example.COM',
        '  jane.doe@example.com  ',
        'Jane Doe <JANE.DOE@example.com>',
        'mailto:Jane.Doe@example.com',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function algorithms(): array
    {
        return [
            'md5' => ['md5'],
            'sha256' => ['sha256'],
        ];
    }

    #[Test]
    #[DataProvider('algorithms')]
    public function it_can_normalize_then_hash_every_spelling_of_an_address_to_one_stable_hash(string $algorithm): void
    {
        $list = $this->makeList($algorithm);
        $expected = hash($algorithm, 'jane.doe@example.com');

        foreach (self::SPELLINGS as $spelling) {
            $this->assertSame($expected, $list->hash($spelling));
            $this->assertSame($expected, $list->hash($list->normalize($spelling)));
        }

        $this->assertTrue($list->add(self::SPELLINGS[0]));
        $this->assertSame([$expected], DB::table('hash_list_entries')->pluck('hash')->all());

        foreach (self::SPELLINGS as $spelling) {
            $this->assertTrue($list->has($spelling));
            $this->assertFalse($list->add($spelling));
        }

        $this->assertSame(1, $list->count());
        $this->assertTrue($list->hasHash($expected));
    }

    #[Test]
    public function it_hashes_the_same_address_differently_for_md5_and_sha256(): void
    {
        $md5 = $this->makeList('md5')->hash('jane.doe@example.com');
        $sha256 = $this->makeList('sha256')->hash('jane.doe@example.com');

        $this->assertSame(32, strlen($md5));
        $this->assertSame(64, strlen($sha256));
        $this->assertNotSame($md5, $sha256);
    }

    #[Test]
    public function it_never_stores_a_plain_email(): void
    {
        $list = $this->makeList();
        $list->add('Jane.Doe@Example.com');
        $list->add('bob@example.org');

        $rows = DB::table('hash_list_entries')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['id', 'list', 'hash', 'created_at'], array_keys((array) $rows[0]));

        $everything = (string) json_encode($rows);
        $this->assertStringNotContainsString('@', $everything);
        $this->assertStringNotContainsString('jane', $everything);
        $this->assertStringNotContainsString('example', $everything);
    }

    #[Test]
    public function it_stores_the_hash_as_lowercase_hex(): void
    {
        $list = $this->makeList();
        $list->addHash(strtoupper(hash('sha256', 'a@example.com')));

        $this->assertSame([hash('sha256', 'a@example.com')], $list->hashes()->all());
    }

    #[Test]
    public function it_can_add_and_check_by_hash(): void
    {
        $list = $this->makeList();
        $hash = hash('sha256', 'a@example.com');

        $this->assertFalse($list->hasHash($hash));
        $this->assertTrue($list->addHash($hash));
        $this->assertFalse($list->addHash($hash));
        $this->assertTrue($list->hasHash($hash));
        $this->assertTrue($list->has('A@Example.com'));
    }

    #[Test]
    public function it_accepts_an_uppercase_or_padded_hash_on_lookup(): void
    {
        $list = $this->makeList();
        $hash = hash('sha256', 'a@example.com');
        $list->addHash($hash);

        $this->assertTrue($list->hasHash(strtoupper($hash)));
        $this->assertTrue($list->hasHash("  {$hash}\n"));
    }

    #[Test]
    public function it_reports_an_email_that_was_never_added_as_not_listed(): void
    {
        $list = $this->makeList();
        $list->add('a@example.com');

        $this->assertFalse($list->has('b@example.com'));
    }

    #[Test]
    public function it_returns_false_instead_of_throwing_for_malformed_lookups(): void
    {
        $list = $this->makeList();

        $this->assertFalse($list->has('not an email'));
        $this->assertFalse($list->hasHash('not a hash'));
        $this->assertFalse($list->hasHash(hash('md5', 'a@example.com')));
        $this->assertFalse($list->remove('not an email'));
        $this->assertFalse($list->removeHash('not a hash'));
        $this->assertFalse($list->matches('not an email', hash('sha256', 'a@example.com')));
        $this->assertFalse($list->matches('a@example.com', 'not a hash'));
    }

    #[Test]
    public function it_throws_when_adding_something_that_cannot_be_listed(): void
    {
        $list = $this->makeList();

        try {
            $list->add('not an email');
            $this->fail('Expected an InvalidEmail exception.');
        } catch (InvalidEmail) {
            $this->assertSame(0, $list->count());
        }

        $this->expectException(InvalidHash::class);
        $list->addHash('abc');
    }

    #[Test]
    public function it_rejects_a_hash_made_with_the_other_algorithm(): void
    {
        $list = $this->makeList('sha256');

        $this->expectException(InvalidHash::class);
        $this->expectExceptionMessage('64-character hexadecimal sha256');

        $list->addHash(hash('md5', 'a@example.com'));
    }

    #[Test]
    public function it_can_remove_by_email_and_by_hash(): void
    {
        $list = $this->makeList();
        $list->add('a@example.com');
        $list->add('b@example.com');

        $this->assertTrue($list->remove('A@example.com'));
        $this->assertFalse($list->has('a@example.com'));
        $this->assertFalse($list->remove('a@example.com'));

        $this->assertTrue($list->removeHash(hash('sha256', 'b@example.com')));
        $this->assertSame(0, $list->count());
    }

    #[Test]
    public function it_keeps_lists_apart(): void
    {
        $default = $this->makeList();
        $news = $default->on('news');

        $news->add('a@example.com');

        $this->assertTrue($news->has('a@example.com'));
        $this->assertFalse($default->has('a@example.com'));
        $this->assertSame('news', $news->listName());
        $this->assertSame('default', $default->listName());

        $default->add('a@example.com');
        $this->assertSame(2, HashListEntry::query()->count());
        $this->assertTrue($news->remove('a@example.com'));
        $this->assertTrue($default->has('a@example.com'));
    }

    #[Test]
    public function it_clears_only_its_own_list(): void
    {
        $default = $this->makeList();
        $news = $default->on('news');
        $default->add('a@example.com');
        $default->add('b@example.com');
        $news->add('a@example.com');

        $this->assertSame(2, $default->clear());
        $this->assertSame(0, $default->count());
        $this->assertSame(1, $news->count());
    }

    #[Test]
    public function it_enforces_uniqueness_in_the_database_itself(): void
    {
        $hash = hash('sha256', 'a@example.com');
        HashListEntry::query()->create(['list' => 'default', 'hash' => $hash]);

        $this->expectException(QueryException::class);

        HashListEntry::query()->create(['list' => 'default', 'hash' => $hash]);
    }

    #[Test]
    public function it_allows_the_same_hash_in_two_lists(): void
    {
        $hash = hash('sha256', 'a@example.com');
        HashListEntry::query()->create(['list' => 'default', 'hash' => $hash]);
        HashListEntry::query()->create(['list' => 'news', 'hash' => $hash]);

        $this->assertSame(2, HashListEntry::query()->count());
    }

    #[Test]
    public function it_compares_an_email_to_a_known_hash(): void
    {
        $list = $this->makeList();
        $hash = hash('sha256', 'jane.doe@example.com');

        $this->assertTrue($list->matches('Jane Doe <JANE.DOE@example.com>', $hash));
        $this->assertTrue($list->matches('jane.doe@example.com', strtoupper($hash)));
        $this->assertFalse($list->matches('bob@example.com', $hash));
    }

    #[Test]
    public function it_treats_plus_tags_as_distinct_unless_told_otherwise(): void
    {
        $plain = $this->makeList();
        $stripping = $this->makeList(stripPlusTags: true);

        $this->assertNotSame($plain->hash('jane+news@example.com'), $plain->hash('jane@example.com'));
        $this->assertSame($stripping->hash('jane+news@example.com'), $stripping->hash('jane@example.com'));
    }

    #[Test]
    public function it_reads_every_hash_back_in_chunks(): void
    {
        $list = $this->makeList(chunkSize: 2);
        $expected = [];

        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $list->add("{$name}@example.com");
            $expected[] = hash('sha256', "{$name}@example.com");
        }

        $this->assertSame($expected, $list->hashes()->values()->all());
    }

    #[Test]
    public function it_validates_list_names(): void
    {
        $list = $this->makeList();

        $this->assertSame(str_repeat('x', 64), $list->on(str_repeat('x', 64))->listName());

        foreach (['banned', 'partner-42', 'a.b_c:d', '7'] as $name) {
            $this->assertSame($name, $list->on($name)->listName());
        }

        foreach ([str_repeat('x', 65), 'News', 'news ', ' news', '-news', 'news letter', "n\u{00E9}ws"] as $name) {
            try {
                $list->on($name);
                $this->fail("'{$name}' should not be a valid list name");
            } catch (InvalidListName) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_rejects_an_empty_list_name(): void
    {
        $this->expectException(InvalidListName::class);

        $this->makeList()->on('');
    }

    #[Test]
    public function it_builds_from_config(): void
    {
        $list = HashList::fromConfig([
            'algorithm' => ' MD5 ',
            'default_list' => 'main',
            'chunk_size' => 10,
            'strip_plus_tags' => 'true',
        ]);

        $this->assertSame(Algorithm::Md5, $list->algorithm());
        $this->assertSame('main', $list->listName());
        $this->assertSame($list->hash('a@example.com'), $list->hash('a+x@example.com'));
    }

    #[Test]
    public function it_reads_an_empty_null_or_missing_on_off_value_as_off(): void
    {
        $base = ['algorithm' => 'sha256', 'default_list' => 'main', 'chunk_size' => 10];

        foreach ([$base, ['strip_plus_tags' => null] + $base, ['strip_plus_tags' => ''] + $base] as $config) {
            $list = HashList::fromConfig($config);

            $this->assertNotSame($list->hash('a@example.com'), $list->hash('a+x@example.com'));
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[Test]
    #[DataProvider('brokenConfigs')]
    public function it_throws_a_named_exception_for_broken_config(array $config, string $message): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($message);

        HashList::fromConfig($config);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function brokenConfigs(): array
    {
        $valid = ['algorithm' => 'sha256', 'default_list' => 'default', 'chunk_size' => 500];

        return [
            'missing algorithm' => [array_diff_key($valid, ['algorithm' => 1]), '[hash_list.algorithm]'],
            'unsupported algorithm' => [['algorithm' => 'sha1'] + $valid, 'not supported'],
            'unsupported algorithm is not repeated' => [['algorithm' => 'jane@example.com'] + $valid, 'value is not supported. Use [md5] or [sha256].'],
            'missing default list' => [array_diff_key($valid, ['default_list' => 1]), '[hash_list.default_list]'],
            'zero chunk size' => [['chunk_size' => 0] + $valid, '[hash_list.chunk_size]'],
            'string chunk size' => [['chunk_size' => '500'] + $valid, '[hash_list.chunk_size]'],
            'typo in strip_plus_tags' => [['strip_plus_tags' => 'ture'] + $valid, '[hash_list.strip_plus_tags]'],
            'array strip_plus_tags' => [['strip_plus_tags' => []] + $valid, '[hash_list.strip_plus_tags]'],
        ];
    }

    #[Test]
    public function it_resolves_from_the_container_using_the_published_config(): void
    {
        $this->assertSame(Algorithm::Sha256, app(HashList::class)->algorithm());

        config()->set('hash_list.algorithm', 'md5');

        $this->assertSame(Algorithm::Md5, app(HashList::class)->algorithm());
    }

    #[Test]
    public function it_works_through_the_facade(): void
    {
        $this->assertTrue(HashListFacade::add('Jane@Example.com'));
        $this->assertTrue(HashListFacade::has('jane@example.com'));
        $this->assertSame(1, HashListFacade::count());
        $this->assertSame(hash('sha256', 'jane@example.com'), HashListFacade::hash('Jane@Example.com'));
    }

    #[Test]
    public function it_reads_the_table_name_from_config(): void
    {
        config()->set('hash_list.table', 'custom_entries');

        $this->assertSame('custom_entries', (new HashListEntry)->getTable());

        config()->set('hash_list.table', '');

        $this->assertSame('hash_list_entries', (new HashListEntry)->getTable());
    }

    #[Test]
    public function it_reads_the_connection_from_config(): void
    {
        $this->assertNull((new HashListEntry)->getConnectionName());

        config()->set('hash_list.connection', 'reporting');

        $this->assertSame('reporting', (new HashListEntry)->getConnectionName());
    }

    #[Test]
    public function it_throws_for_a_table_or_connection_that_is_not_a_string(): void
    {
        config()->set('hash_list.connection', ['mysql']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[hash_list.connection]');

        (new HashListEntry)->getConnectionName();
    }

    #[Test]
    public function it_reads_on_off_values_the_way_env_files_spell_them(): void
    {
        foreach ([true, 'true', '1', 1, 'on', 'yes', 'TRUE'] as $on) {
            $this->assertSame(
                $this->makeList()->hash('a@example.com'),
                HashList::fromConfig(['algorithm' => 'sha256', 'default_list' => 'default', 'chunk_size' => 5, 'strip_plus_tags' => $on])->hash('a+tag@example.com'),
                var_export($on, true).' should switch plus-tag stripping on',
            );
        }

        foreach ([false, 'false', '0', 0, 'off', 'no', '', null] as $off) {
            $this->assertNotSame(
                $this->makeList()->hash('a@example.com'),
                HashList::fromConfig(['algorithm' => 'sha256', 'default_list' => 'default', 'chunk_size' => 5, 'strip_plus_tags' => $off])->hash('a+tag@example.com'),
                var_export($off, true).' should leave plus-tag stripping off',
            );
        }
    }

    #[Test]
    public function it_names_the_connection_the_list_lives_on(): void
    {
        $this->assertSame('testing', $this->makeList()->connection()->getName());

        config()->set('database.connections.second', config('database.connections.testing'));
        config()->set('hash_list.connection', 'second');

        $this->assertSame('second', $this->makeList()->connection()->getName());
    }

    #[Test]
    public function it_refuses_a_plain_email_written_through_the_model(): void
    {
        $this->expectException(InvalidHash::class);

        HashListEntry::query()->create(['list' => 'default', 'hash' => 'person@example.com']);
    }

    #[Test]
    public function it_refuses_a_bad_list_name_written_through_the_model(): void
    {
        $this->expectException(InvalidListName::class);

        HashListEntry::query()->create(['list' => 'News', 'hash' => hash('sha256', 'a@example.com')]);
    }

    #[Test]
    public function it_throws_when_the_whole_config_is_not_an_array(): void
    {
        config()->set('hash_list', 'sha256');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[hash_list] configuration must be an array');

        app(HashList::class);
    }

    #[Test]
    public function it_refuses_a_plain_email_even_without_model_events(): void
    {
        try {
            HashListEntry::query()->getModel()->newInstance()->forceFill(['list' => 'default', 'hash' => 'person@example.com'])->saveQuietly();
            $this->fail('A plain email should not reach the table.');
        } catch (InvalidHash) {
            $this->assertSame(0, HashListEntry::query()->count());
        }

        $this->expectException(InvalidHash::class);

        HashListEntry::createQuietly(['list' => 'default', 'hash' => 'person@example.com']);
    }

    #[Test]
    public function it_refuses_a_scalar_config_when_the_model_reads_its_table(): void
    {
        config()->set('hash_list', 'sha256');

        $this->expectException(InvalidConfiguration::class);

        (new HashListEntry)->getTable();
    }

    #[Test]
    public function it_never_repeats_an_unsupported_algorithm_in_the_message(): void
    {
        try {
            HashList::fromConfig(['algorithm' => 'jane@example.com', 'default_list' => 'default', 'chunk_size' => 5]);
            $this->fail('Expected InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            $this->assertStringNotContainsString('jane', $e->getMessage());
        }
    }

    #[Test]
    public function it_refuses_a_plain_email_set_as_a_raw_attribute(): void
    {
        $entry = new HashListEntry;
        $entry->setRawAttributes(['list' => 'default', 'hash' => 'person@example.com']);

        try {
            $entry->saveQuietly();
            $this->fail('A plain email should not reach the table.');
        } catch (InvalidHash) {
            $this->assertSame(0, HashListEntry::query()->count());
        }
    }

    #[Test]
    public function it_refuses_a_raw_attribute_on_update_too(): void
    {
        $entry = HashListEntry::query()->create(['list' => 'default', 'hash' => hash('sha256', 'a@example.com')]);
        $entry->setRawAttributes(['hash' => 'person@example.com'] + $entry->getAttributes());

        $this->expectException(InvalidHash::class);

        $entry->saveQuietly();
    }

    #[Test]
    public function it_refuses_a_plain_email_swapped_in_by_a_creating_listener(): void
    {
        HashListEntry::creating(static function (HashListEntry $entry): void {
            $entry->setRawAttributes(['hash' => 'person@example.com'] + $entry->getAttributes());
        });

        try {
            HashListEntry::query()->create(['list' => 'default', 'hash' => hash('sha256', 'a@example.com')]);
            $this->fail('A plain email should not reach the table.');
        } catch (InvalidHash) {
            $this->assertSame(0, HashListEntry::query()->count());
        } finally {
            HashListEntry::flushEventListeners();
        }
    }

    #[Test]
    public function it_refuses_a_plain_email_swapped_in_by_an_updating_listener(): void
    {
        $entry = HashListEntry::query()->create(['list' => 'default', 'hash' => hash('sha256', 'a@example.com')]);

        HashListEntry::updating(static function (HashListEntry $entry): void {
            $entry->setRawAttributes(['hash' => 'person@example.com'] + $entry->getAttributes());
        });

        try {
            $entry->hash = hash('sha256', 'b@example.com');
            $entry->save();
            $this->fail('A plain email should not reach the table.');
        } catch (InvalidHash) {
            $this->assertSame(hash('sha256', 'a@example.com'), HashListEntry::query()->value('hash'));
        } finally {
            HashListEntry::flushEventListeners();
        }
    }

    #[Test]
    public function it_refuses_a_plain_email_in_any_other_column(): void
    {
        $hash = hash('sha256', 'a@example.com');

        foreach ([
            ['list' => 'default', 'hash' => $hash, 'created_at' => 'person@example.com'],
            ['list' => 'default', 'hash' => $hash, 'id' => 'person@example.com'],
            ['list' => 'default', 'hash' => $hash, 'note' => 'person@example.com'],
            ['list' => 'default', 'hash' => $hash, 'person@example.com' => 'x'],
        ] as $row) {
            $entry = new HashListEntry;
            $entry->setRawAttributes($row);

            try {
                $entry->saveQuietly();
                $this->fail('A plain email should not reach the table: '.implode(', ', array_keys($row)));
            } catch (InvalidHash $e) {
                $this->assertStringNotContainsString('person', $e->getMessage());
            }
        }

        $this->assertSame(0, HashListEntry::query()->count());
    }

    #[Test]
    public function it_refuses_a_plain_email_in_created_at_on_update_and_from_a_listener(): void
    {
        $entry = HashListEntry::query()->create(['list' => 'default', 'hash' => hash('sha256', 'a@example.com')]);

        HashListEntry::updating(static function (HashListEntry $entry): void {
            $entry->setRawAttributes(['created_at' => 'person@example.com'] + $entry->getAttributes());
        });

        $before = HashListEntry::query()->toBase()->value('created_at');

        try {
            $entry->hash = hash('sha256', 'b@example.com');
            $entry->save();
            $this->fail('A plain email should not reach the table.');
        } catch (InvalidHash $e) {
            $this->assertStringNotContainsString('person', $e->getMessage());
            $this->assertSame($before, HashListEntry::query()->toBase()->value('created_at'));
            $this->assertSame(hash('sha256', 'a@example.com'), HashListEntry::query()->value('hash'));
        } finally {
            HashListEntry::flushEventListeners();
        }
    }

    #[Test]
    public function the_facade_follows_the_current_config_like_the_container(): void
    {
        $this->assertSame(Algorithm::Sha256, HashListFacade::algorithm());

        config()->set('hash_list.algorithm', 'md5');
        config()->set('hash_list.default_list', 'other');

        $this->assertSame(Algorithm::Md5, HashListFacade::algorithm());
        $this->assertSame('other', HashListFacade::listName());
        $this->assertSame(app(HashList::class)->algorithm(), HashListFacade::algorithm());
    }
}
