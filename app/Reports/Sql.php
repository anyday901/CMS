<?php

namespace App\Reports;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Date expressions for grouping, in the database's own dialect. */
class Sql
{
    /** "2026-10" for a date or timestamp column. */
    public static function month(string $column): string
    {
        return self::format($column, '%Y-%m', 'YYYY-MM');
    }

    /** "2026-10-15" for a date or timestamp column. */
    public static function day(string $column): string
    {
        return self::format($column, '%Y-%m-%d', 'YYYY-MM-DD');
    }

    /**
     * One value for each group: $value (such as "SUM(amount)") by $key
     * (a column or an expression from month() or day()).
     *
     * @return Collection<string, mixed>
     */
    public static function by(Builder $query, string $key, string $value): Collection
    {
        return $query->selectRaw("{$key} as group_key, {$value} as group_value")->groupByRaw($key)->pluck('group_value', 'group_key');
    }

    private static function format(string $column, string $strftime, string $pgsql): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('{$strftime}', {$column})",
            'pgsql' => "to_char({$column}, '{$pgsql}')",
            'sqlsrv' => "format({$column}, '".str_replace(['%Y', '%m', '%d'], ['yyyy', 'MM', 'dd'], $strftime)."')",
            default => "DATE_FORMAT({$column}, '{$strftime}')",
        };
    }
}
