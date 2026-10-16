<?php

namespace App\System\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Writes the whole database as SQL, gzipped, without mysqldump: table
 * definitions, then rows in batches. Works with MySQL, MariaDB and SQLite.
 * Each statement ends with ";" at the end of a line, so the file loads with
 * the mysql or sqlite3 command line, or `php artisan backup:restore`.
 */
class DatabaseDumper
{
    private const ROWS_PER_INSERT = 100;

    /** @var resource */
    private $out;

    public function __construct(private ?Connection $connection = null)
    {
        $this->connection ??= DB::connection();
    }

    public static function supports(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb', 'sqlite'], true);
    }

    /** Dumps into a .sql.gz file and returns how many tables it wrote. */
    public function dump(string $path): int
    {
        $driver = $this->connection->getDriverName();

        if (! self::supports($driver)) {
            throw new BackupFailed("Backups from the admin area work with MySQL, MariaDB and SQLite. This database uses {$driver}; back it up with its own tools, such as pg_dump.");
        }

        $out = gzopen($path, 'wb6');
        if ($out === false) {
            throw new BackupFailed("Can't write {$path}.");
        }
        $this->out = $out;

        $snapshot = $this->beginSnapshot($driver);

        try {
            $this->line('-- CMS database backup, '.now()->toIso8601String().', '.config('app.name'));

            return $driver === 'sqlite' ? $this->sqlite() : $this->mysql();
        } finally {
            if ($snapshot) {
                $this->connection->getPdo()->exec('COMMIT');
            }
            gzclose($this->out);
        }
    }

    /**
     * Reads every table from one consistent snapshot, as mysqldump
     * --single-transaction does, so rows written while the backup runs can't
     * leave a child row in it without its parent. Skipped inside a
     * transaction that's already open, which sees one snapshot anyway.
     */
    private function beginSnapshot(string $driver): bool
    {
        if ($this->connection->transactionLevel() > 0) {
            return false;
        }

        $pdo = $this->connection->getPdo();

        if ($driver === 'sqlite') {
            $pdo->exec('BEGIN');
        } else {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }

        return true;
    }

    private function mysql(): int
    {
        $this->line('SET NAMES utf8mb4;');
        $this->line('SET FOREIGN_KEY_CHECKS=0;');
        $this->line("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';");

        $tables = collect($this->connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(fn ($row) => (string) array_values((array) $row)[0]);

        foreach ($tables as $table) {
            $quoted = '`'.str_replace('`', '``', $table).'`';
            $create = (array) $this->connection->selectOne("SHOW CREATE TABLE {$quoted}");
            $this->line('');
            $this->line("DROP TABLE IF EXISTS {$quoted};");
            $this->line(str_replace("\n", ' ', (string) ($create['Create Table'] ?? array_values($create)[1])).';');
            $this->rows($quoted, fn (string $column) => '`'.str_replace('`', '``', $column).'`', mysql: true);
        }

        $this->line('SET FOREIGN_KEY_CHECKS=1;');

        return $tables->count();
    }

    private function sqlite(): int
    {
        $this->line('PRAGMA foreign_keys=OFF;');
        $this->line('BEGIN TRANSACTION;');
        // Foreign keys are checked once everything is back, not table by table.
        $this->line('PRAGMA defer_foreign_keys=ON;');

        $objects = collect($this->connection->select("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name"));
        $tables = $objects->where('type', 'table');

        foreach ($tables as $table) {
            $quoted = '"'.str_replace('"', '""', $table->name).'"';
            $this->line('');
            $this->line("DROP TABLE IF EXISTS {$quoted};");
            $this->line(str_replace(["\r", "\n"], ' ', $table->sql).';');
            $this->rows($quoted, fn (string $column) => '"'.str_replace('"', '""', $column).'"', mysql: false);
        }

        foreach ($objects->where('type', '!=', 'table') as $object) {
            $this->line(str_replace(["\r", "\n"], ' ', $object->sql).';');
        }

        $this->line('COMMIT;');

        return $tables->count();
    }

    /** @param  callable(string): string  $quoteColumn */
    private function rows(string $quoted, callable $quoteColumn, bool $mysql): void
    {
        $pdo = $this->connection->getPdo();

        if ($mysql) {
            // Stream rows rather than load a big table into memory.
            $pdo->setAttribute(self::bufferedQueryAttribute(), false);
        }

        try {
            $statement = $pdo->query("SELECT * FROM {$quoted}");
            $columns = null;
            $batch = [];

            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columns ??= implode(', ', array_map($quoteColumn, array_keys($row)));
                $batch[] = '('.implode(', ', array_map(fn ($value) => $this->value($pdo, $value, $mysql), $row)).')';

                if (count($batch) === self::ROWS_PER_INSERT) {
                    $this->line("INSERT INTO {$quoted} ({$columns}) VALUES ".implode(', ', $batch).';');
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->line("INSERT INTO {$quoted} ({$columns}) VALUES ".implode(', ', $batch).';');
            }

            $statement->closeCursor();
        } finally {
            if ($mysql) {
                $pdo->setAttribute(self::bufferedQueryAttribute(), true);
            }
        }
    }

    private function value(PDO $pdo, mixed $value, bool $mysql): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            // Binary data, and anything else that isn't text, goes in as hex so the file stays valid text.
            ! mb_check_encoding((string) $value, 'UTF-8') || str_contains((string) $value, "\0") => $mysql ? '0x'.bin2hex((string) $value) : "X'".bin2hex((string) $value)."'",
            default => $this->quote($pdo, (string) $value),
        };
    }

    /** Quotes text, keeping line breaks escaped so each statement stays on one line. */
    private function quote(PDO $pdo, string $value): string
    {
        if ($this->connection->getDriverName() !== 'sqlite' || ! preg_match('/[\r\n]/', $value)) {
            // MySQL's quote() already writes line breaks as \n and \r.
            return $pdo->quote($value);
        }

        // SQLite strings have no escapes, so line breaks are joined in with char().
        return collect(preg_split('/([\r\n])/', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $part) => match ($part) {
                "\r" => 'char(13)',
                "\n" => 'char(10)',
                default => "'".str_replace("'", "''", $part)."'",
            })
            ->join(' || ');
    }

    /** PHP 8.4 moved the MySQL attributes to Pdo\Mysql and 8.5 deprecates the old names. */
    private static function bufferedQueryAttribute(): int
    {
        return class_exists('Pdo\\Mysql') ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    }

    private function line(string $text): void
    {
        gzwrite($this->out, $text."\n");
    }
}
