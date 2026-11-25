<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Keaton\HashList\Exceptions\InvalidConfiguration;
use Keaton\HashList\HashListServiceProvider;
use Keaton\HashList\Models\HashListEntry;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

class ServiceProviderTest extends TestCase
{
    #[Test]
    public function it_merges_the_default_config(): void
    {
        $this->assertSame('sha256', config('hash_list.algorithm'));
        $this->assertSame('hash_list_entries', config('hash_list.table'));
        $this->assertNull(config('hash_list.connection'));
        $this->assertSame('default', config('hash_list.default_list'));
        $this->assertFalse(config('hash_list.strip_plus_tags'));
        $this->assertSame(500, config('hash_list.chunk_size'));
        $this->assertFalse(config('hash_list.guard'));
        $this->assertNull(config('hash_list.guard_list'));
    }

    #[Test]
    public function it_publishes_the_config_and_the_migration(): void
    {
        $config = ServiceProvider::pathsToPublish(HashListServiceProvider::class, 'hash_list-config');
        $migration = ServiceProvider::pathsToPublish(HashListServiceProvider::class, 'hash_list-migrations');

        $this->assertSame(
            [(string) realpath(__DIR__.'/../config/hash_list.php')],
            array_map(static fn (string $path): string => (string) realpath($path), array_keys($config)),
        );
        $this->assertSame(
            [(string) realpath(__DIR__.'/../database/migrations/create_hash_list_entries_table.php.stub')],
            array_map(static fn (string $path): string => (string) realpath($path), array_keys($migration)),
        );
        $this->assertMatchesRegularExpression(
            '/\d{4}_\d{2}_\d{2}_\d{6}_create_hash_list_entries_table\.php$/',
            (string) array_values($migration)[0],
        );
    }

    #[Test]
    public function it_creates_the_table_with_a_unique_index_over_list_and_hash(): void
    {
        $this->assertTrue(Schema::hasTable('hash_list_entries'));
        $this->assertTrue(Schema::hasColumns('hash_list_entries', ['id', 'list', 'hash', 'created_at']));

        $unique = collect(Schema::getIndexes('hash_list_entries'))
            ->first(static fn (array $index): bool => $index['unique'] === true && ! $index['primary']);

        $this->assertIsArray($unique);
        $this->assertSame(['list', 'hash'], $unique['columns']);
    }

    #[Test]
    public function it_builds_the_migration_for_a_custom_table_name(): void
    {
        // what a user does: edit the published migration, and set the config to match
        $migration = include __DIR__.'/../database/migrations/create_hash_list_entries_table.php.stub';
        (new ReflectionProperty($migration, 'table'))->setValue($migration, 'custom_hash_entries');
        config()->set('hash_list.table', 'custom_hash_entries');

        $migration->up();

        $this->assertTrue(Schema::hasTable('custom_hash_entries'));

        HashListEntry::query()->create(['list' => 'default', 'hash' => hash('sha256', 'a@example.com')]);

        $this->assertSame(1, HashListEntry::query()->count());

        $migration->down();

        $this->assertFalse(Schema::hasTable('custom_hash_entries'));
    }

    #[Test]
    public function it_rolls_back_the_table_it_made_whatever_the_config_says_later(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_hash_list_entries_table.php.stub';
        (new ReflectionProperty($migration, 'table'))->setValue($migration, 'made_by_this_migration');
        $migration->up();

        Schema::create('someone_elses_table', static fn ($table) => $table->id());
        config()->set('hash_list.table', 'someone_elses_table');

        $migration->down();

        $this->assertFalse(Schema::hasTable('made_by_this_migration'));
        $this->assertTrue(Schema::hasTable('someone_elses_table'));
    }

    #[Test]
    public function it_ships_a_migration_that_matches_the_default_config(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_hash_list_entries_table.php.stub';
        $defaults = require __DIR__.'/../config/hash_list.php';

        $this->assertSame($defaults['table'], (new ReflectionProperty($migration, 'table'))->getValue($migration));
        $this->assertNull($migration->getConnection());
    }

    #[Test]
    public function it_keeps_the_entry_model_a_plain_eloquent_model(): void
    {
        $entry = HashListEntry::query()->create(['list' => 'default', 'hash' => hash('sha256', 'a@example.com')]);

        $this->assertInstanceOf(Model::class, $entry);
        $this->assertNotNull($entry->created_at);
        $this->assertNull($entry->getUpdatedAtColumn());
    }

    #[Test]
    public function it_refuses_a_scalar_config_before_merging_into_it(): void
    {
        config()->set('hash_list', 'sha256');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[hash_list] configuration must be an array');

        (new HashListServiceProvider(app()))->register();
    }
}
