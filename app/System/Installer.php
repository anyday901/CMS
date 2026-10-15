<?php

namespace App\System;

use App\Enums\StaffRole;
use App\Models\Activity;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * The web installer at /install: checks the server, writes the database
 * settings to .env, creates the tables and the first staff account. It
 * stops answering once a staff account exists.
 */
class Installer
{
    public const DRIVERS = ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'sqlite' => 'SQLite (a file; fine for trying CMS out)'];

    private static ?bool $installed = null;

    public function __construct(private EnvFile $env) {}

    public static function enabled(): bool
    {
        return (bool) config('installer.enabled', true);
    }

    public function marker(): string
    {
        return storage_path('app/installed');
    }

    /** Whether setup is done: the marker file is there, or (for installs older than the installer) a staff account exists. */
    public function installed(): bool
    {
        if (self::$installed || is_file($this->marker())) {
            return self::$installed = true;
        }

        if ($this->hasStaff()) {
            $this->markInstalled();

            return self::$installed = true;
        }

        return false;
    }

    public static function forget(): void
    {
        self::$installed = null;
    }

    /** Which step the installer is on: requirements, database or account. */
    public function step(): string
    {
        return match (true) {
            collect($this->requirements())->contains('ok', false) => 'requirements',
            ! $this->databaseReady() => 'database',
            default => 'account',
        };
    }

    /** @return list<array{label: string, ok: bool, detail: string}> */
    public function requirements(): array
    {
        $missing = array_values(array_filter(HealthCheck::EXTENSIONS, fn (string $extension) => ! extension_loaded($extension)));
        $folders = [storage_path(), storage_path('framework/views'), storage_path('logs'), base_path('bootstrap/cache')];
        $unwritable = array_values(array_filter($folders, fn (string $folder) => ! is_writable($folder)));

        return [
            ['label' => 'PHP 8.3 or newer', 'ok' => version_compare(PHP_VERSION, '8.3.0', '>='), 'detail' => 'This server has PHP '.PHP_VERSION.'.'],
            ['label' => 'PHP extensions', 'ok' => $missing === [], 'detail' => $missing === [] ? 'All there.' : 'Missing: '.implode(', ', $missing).'.'],
            ['label' => 'A MySQL, MariaDB or SQLite driver for PHP', 'ok' => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'), 'detail' => 'pdo_mysql or pdo_sqlite.'],
            ['label' => 'Writable folders', 'ok' => $unwritable === [], 'detail' => $unwritable === [] ? 'storage/ and bootstrap/cache/ are writable.' : 'The web server can\'t write to '.implode(', ', $unwritable).'.'],
            ['label' => 'A writable .env file', 'ok' => $this->env->writable(), 'detail' => $this->env->writable() ? $this->env->path().' can be written.' : 'Make '.$this->env->path().' writable by the web server for setup (and read-only again afterwards), or fill it in by hand.'],
            ['label' => 'Built assets', 'ok' => is_file(public_path('build/manifest.json')), 'detail' => is_file(public_path('build/manifest.json')) ? 'public/build is there.' : 'Run npm ci && npm run build, or use a release download, which has them.'],
        ];
    }

    /** Whether the database connects and its tables are there. */
    public function databaseReady(): bool
    {
        try {
            return Schema::hasTable('users') && Schema::hasTable('migrations');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Tries the connection, writes it to .env, and creates the tables.
     *
     * @param  array{driver: string, host?: string|null, port?: int|string|null, database: string, username?: string|null, password?: string|null}  $db
     *
     * @throws RuntimeException when it can't connect or migrate
     */
    public function configureDatabase(array $db, string $url): void
    {
        $driver = $db['driver'];
        $connection = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => $this->sqlitePath($db['database']), 'prefix' => '', 'foreign_key_constraints' => true]
            : array_merge((array) config("database.connections.{$driver}"), [
                'host' => $db['host'] ?: '127.0.0.1',
                'port' => (int) ($db['port'] ?: 3306),
                'database' => $db['database'],
                'username' => $db['username'] ?? '',
                'password' => $db['password'] ?? '',
                'url' => null,
            ]);

        if ($driver === 'sqlite' && ! is_file($connection['database'])) {
            if (! is_writable(dirname($connection['database'])) || ! touch($connection['database'])) {
                throw new RuntimeException("Can't create the database file {$connection['database']}.");
            }
        }

        $previous = config("database.connections.{$driver}");
        config(["database.connections.{$driver}" => $connection]);
        DB::purge($driver);

        try {
            DB::connection($driver)->getPdo();
        } catch (Throwable $e) {
            config(["database.connections.{$driver}" => $previous]);
            DB::purge($driver);

            throw new RuntimeException('Couldn\'t connect to the database: '.$e->getMessage());
        }

        config(['database.default' => $driver]);

        $this->env->set(array_filter([
            'APP_KEY' => blank(config('app.key')) ? self::newKey() : null,
            'APP_URL' => rtrim($url, '/'),
            'APP_ENV' => 'production',
            'APP_DEBUG' => false,
            'DB_CONNECTION' => $driver,
            'DB_HOST' => $driver === 'sqlite' ? null : $connection['host'],
            'DB_PORT' => $driver === 'sqlite' ? null : $connection['port'],
            'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $driver === 'sqlite' ? null : $connection['username'],
            'DB_PASSWORD' => $driver === 'sqlite' ? null : (string) $connection['password'],
        ], fn ($value) => $value !== null));

        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Creating the tables failed: '.trim(Artisan::output()));
        }

        Artisan::call('config:clear');
    }

    /** Creates the first staff account and the company name, and closes the installer. */
    public function finish(string $company, string $name, string $email, string $password): User
    {
        if ($this->hasStaff()) {
            throw new RuntimeException('Setup is already finished.');
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => StaffRole::Admin,
        ]);

        Settings::save(['billing.company.name' => $company]);
        if ($this->env->writable()) {
            $this->env->set(['APP_NAME' => $company]);
        }
        $this->markInstalled();
        Activity::record('Finished setting up CMS with the web installer', $user);

        return $user;
    }

    /** Fills in APP_KEY when it is blank, so the very first page (the installer) can load. */
    public function ensureAppKey(): void
    {
        if (filled(config('app.key')) || ! $this->env->writable()) {
            return;
        }

        $key = self::newKey();
        $this->env->set(['APP_KEY' => $key]);
        config(['app.key' => $key]);
    }

    private static function newKey(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')));
    }

    private function hasStaff(): bool
    {
        try {
            return Schema::hasTable('users') && User::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function markInstalled(): void
    {
        @file_put_contents($this->marker(), now()->toIso8601String()."\n");
    }

    /** A bare file name goes in database/; a full path is used as it is. */
    private function sqlitePath(string $database): string
    {
        return str_contains($database, '/') ? $database : database_path($database ?: 'database.sqlite');
    }
}
