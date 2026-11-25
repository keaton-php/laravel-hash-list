<?php

declare(strict_types=1);

namespace Keaton\HashList\Tests;

use Illuminate\Support\Facades\Schema;
use Keaton\HashList\HashList;
use Keaton\HashList\HashListServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

class TestCase extends Orchestra
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            HashListServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', static::databaseConnection());
    }

    /**
     * SQLite in memory, unless CI asks for MySQL or PostgreSQL with
     * HASH_LIST_TEST_DB (see .github/workflows/run-tests.yml).
     *
     * @return array<string, mixed>
     */
    protected static function databaseConnection(): array
    {
        $driver = getenv('HASH_LIST_TEST_DB') ?: 'sqlite';
        $server = [
            'host' => getenv('HASH_LIST_TEST_DB_HOST') ?: '127.0.0.1',
            'database' => 'hash_list',
            'username' => getenv('HASH_LIST_TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('HASH_LIST_TEST_DB_PASSWORD') ?: '',
            'prefix' => '',
        ];

        return match ($driver) {
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'mysql' => $server + ['driver' => 'mysql', 'port' => 3306, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            'pgsql' => $server + ['driver' => 'pgsql', 'port' => 5432, 'charset' => 'utf8', 'search_path' => 'public'],
            default => throw new RuntimeException("Unknown HASH_LIST_TEST_DB [{$driver}]."),
        };
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_hash_list_entries_table.php.stub';

        $migration->up();

        // A server database outlives the test, so leave it as we found it.
        // (by name: a test may have changed the table or connection settings)
        $this->beforeApplicationDestroyed(static fn () => Schema::connection('testing')->dropIfExists('hash_list_entries'));
    }

    protected function makeList(
        string $algorithm = 'sha256',
        int $chunkSize = 500,
        bool $stripPlusTags = false,
        string $list = 'default',
    ): HashList {
        return HashList::fromConfig([
            'algorithm' => $algorithm,
            'default_list' => $list,
            'chunk_size' => $chunkSize,
            'strip_plus_tags' => $stripPlusTags,
        ]);
    }

    /**
     * An in-memory stream holding the given text, rewound and ready to read.
     *
     * @return resource
     */
    protected function streamOf(string $contents)
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertNotFalse($stream);

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
