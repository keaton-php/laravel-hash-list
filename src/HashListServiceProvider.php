<?php

declare(strict_types=1);

namespace Keaton\HashList;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\ServiceProvider;
use Keaton\HashList\Exceptions\InvalidConfiguration;
use Keaton\HashList\Mail\RecipientGuard;
use Keaton\HashList\Support\ConfigFlag;

class HashListServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // mergeConfigFrom() would fail with a TypeError on a scalar
        $existing = $this->app->make(Repository::class)->get('hash_list');

        if ($existing !== null && ! is_array($existing)) {
            throw InvalidConfiguration::notAnArray();
        }

        $this->mergeConfigFrom(__DIR__.'/../config/hash_list.php', 'hash_list');

        $this->app->bind(HashList::class, function (Application $app): HashList {
            return HashList::fromConfig(static::config($app));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/hash_list.php' => config_path('hash_list.php'),
            ], 'hash_list-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_hash_list_entries_table.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His').'_create_hash_list_entries_table.php',
                ),
            ], 'hash_list-migrations');
        }

        // The switch is read on every send, so it can be flipped at runtime.
        $this->app->make(Dispatcher::class)->listen(MessageSending::class, function (MessageSending $event): ?bool {
            if (! ConfigFlag::read(static::config($this->app), 'guard')) {
                return null;
            }

            return $this->app->make(RecipientGuard::class)->handle($event);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected static function config(Application $app): array
    {
        $config = $app->make(Repository::class)->get('hash_list', []);

        if (! is_array($config)) {
            throw InvalidConfiguration::notAnArray();
        }

        /** @var array<string, mixed> $config */
        return $config;
    }
}
