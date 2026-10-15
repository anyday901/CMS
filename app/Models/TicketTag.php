<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/** A label staff put on tickets, such as "billing" or "outage", to find and group them. */
class TicketTag extends Model
{
    public const MAX_LENGTH = 50;

    protected $fillable = ['name'];

    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class);
    }

    /**
     * Trimmed, lower case, without duplicates or blanks.
     *
     * @param  iterable<string>  $names
     * @return list<string>
     */
    public static function normalize(iterable $names): array
    {
        return collect($names)
            ->map(fn ($name) => Str::limit(Str::lower(trim(preg_replace('/\s+/', ' ', (string) $name))), self::MAX_LENGTH, ''))
            ->filter(fn (string $name) => $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> "billing, Outage ,," -> ["billing", "outage"] */
    public static function parse(?string $input): array
    {
        return self::normalize(explode(',', (string) $input));
    }
}
