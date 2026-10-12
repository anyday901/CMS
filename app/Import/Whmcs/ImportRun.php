<?php

namespace App\Import\Whmcs;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What every part of one WHMCS import shares: the WHMCS database, the report,
 * the WHMCS currencies and the rows waiting for a batch insert.
 */
class ImportRun
{
    private const BATCH = 500;

    public readonly string $now;

    public readonly string $billingCurrency;

    /** @var array<int, string> WHMCS currency id => code */
    public readonly array $currencies;

    private readonly string $defaultCurrency;

    /** @var array<string, list<array<string, mixed>>> rows waiting for a batch insert */
    private array $pending = [];

    public function __construct(public readonly ConnectionInterface $source, public readonly ImportReport $report)
    {
        $this->now = now()->toDateTimeString();
        $this->billingCurrency = (string) config('billing.currency');

        $currencies = [];
        $default = null;

        foreach ($source->table('tblcurrencies')->orderBy('id')->get() as $currency) {
            $currencies[(int) $currency->id] = strtoupper((string) $currency->code);

            if (Whmcs::on($currency->default ?? '')) {
                $default = strtoupper((string) $currency->code);
            }
        }

        $this->currencies = $currencies;
        $this->defaultCurrency = $default ?? $currencies[array_key_first($currencies)] ?? $this->billingCurrency;
    }

    public function table(string $table): Builder
    {
        return $this->source->table($table);
    }

    /** The currency code of a WHMCS currency id, or the WHMCS default. */
    public function currency(mixed $id): string
    {
        return $this->currencies[(int) $id] ?? $this->defaultCurrency;
    }

    public function today(): string
    {
        return substr($this->now, 0, 10);
    }

    public function hasTables(string ...$tables): bool
    {
        foreach ($tables as $table) {
            if (! $this->source->getSchemaBuilder()->hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    public function setting(string $name): ?string
    {
        if (! $this->hasTables('tblconfiguration')) {
            return null;
        }

        $value = $this->table('tblconfiguration')->where('setting', $name)->value('value');

        return $value === null ? null : (string) $value;
    }

    /** @param  array<string, mixed>  $row */
    public function queue(string $table, array $row): void
    {
        $this->pending[$table][] = $row;

        if (count($this->pending[$table]) >= self::BATCH) {
            $this->flush($table);
        }
    }

    public function flush(string $table): void
    {
        if (($this->pending[$table] ?? []) !== []) {
            DB::table($table)->insert($this->pending[$table]);
        }

        $this->pending[$table] = [];
    }

    public function flushAll(): void
    {
        foreach (array_keys($this->pending) as $table) {
            $this->flush($table);
        }
    }

    /** Reads a WHMCS table in batches of rows, so large installs never sit in memory at once. */
    public function chunk(string $table, callable $callback): void
    {
        $this->table($table)->chunkById(self::BATCH, $callback);
    }
}
