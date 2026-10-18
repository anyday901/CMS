<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A counter that hands out numbers in order with no gaps, such as paid
 * invoice numbers. Numbers are taken inside the caller's transaction: the
 * row stays locked until it commits, and a rollback gives the number back.
 */
class NumberSequence extends Model
{
    public const INVOICE = 'invoice';

    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['name', 'next'];

    protected function casts(): array
    {
        return ['next' => 'integer'];
    }

    /** Takes the next number and moves the sequence on by one. */
    public static function take(string $name): int
    {
        return DB::transaction(function () use ($name) {
            static::firstOrCreate(['name' => $name], ['next' => 1]);
            $sequence = static::lockForUpdate()->findOrFail($name);
            $number = $sequence->next;
            $sequence->update(['next' => $number + 1]);

            return $number;
        });
    }

    /** The number the next take() will give. */
    public static function peek(string $name): int
    {
        return (int) (static::whereKey($name)->value('next') ?? 1);
    }

    /** Sets the next number, such as to carry on from another system's numbers. */
    public static function set(string $name, int $next): void
    {
        DB::transaction(function () use ($name, $next) {
            static::firstOrCreate(['name' => $name], ['next' => $next]);
            static::lockForUpdate()->findOrFail($name)->update(['next' => $next]);
        });
    }
}
