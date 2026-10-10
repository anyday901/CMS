<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

/** One line in the audit trail: who did what, to what, and when. */
class Activity extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'activity_log';

    protected $fillable = [
        'actor_type', 'actor_id', 'actor_name', 'client_id', 'subject_type', 'subject_id',
        'description', 'properties', 'ip_address',
    ];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    /**
     * Records an action by whoever is signed in: staff first, then a portal
     * client, otherwise the system (scheduler, webhooks, console).
     */
    public static function record(string $description, ?Model $subject = null, array $properties = [], ?Client $client = null): self
    {
        [$type, $id, $name] = match (true) {
            Auth::guard('web')->check() => ['staff', Auth::guard('web')->id(), Auth::guard('web')->user()->name],
            Auth::guard('client')->check() => ['client', Auth::guard('client')->id(), Auth::guard('client')->user()->fullName()],
            default => ['system', null, 'System'],
        };

        return static::create([
            'actor_type' => $type,
            'actor_id' => $id,
            'actor_name' => $name,
            'client_id' => ($client ?? self::clientOf($subject))?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties ?: null,
            // Console runs have a request object too, but no route and no real IP.
            'ip_address' => request()->route() ? request()->ip() : null,
        ]);
    }

    private static function clientOf(?Model $subject): ?Client
    {
        return match (true) {
            $subject instanceof Client => $subject,
            $subject !== null && $subject->getAttribute('client_id') !== null => Client::find($subject->getAttribute('client_id')),
            default => null,
        };
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
