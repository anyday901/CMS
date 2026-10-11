<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * An extra field staff add to clients or services, such as a VAT number or
 * a server hostname. Values are kept on the client or service by field id.
 * A service field can be for every product or for one product. Fields marked
 * admin only are hidden from clients.
 */
class CustomField extends Model
{
    public const CLIENT = 'client';

    public const SERVICE = 'service';

    public const CHECKBOX = 'checkbox';

    public const TYPES = ['text' => 'Text', 'textarea' => 'Text area', 'dropdown' => 'Dropdown', self::CHECKBOX => 'Checkbox'];

    protected $fillable = ['entity', 'product_id', 'name', 'type', 'options', 'help', 'required', 'admin_only', 'sort_order'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'admin_only' => 'boolean', 'sort_order' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /** @return Builder<self> */
    public static function forClients(): Builder
    {
        return static::where('entity', self::CLIENT)->ordered();
    }

    /** @return Builder<self> Fields for every product and for this one. */
    public static function forService(?int $productId): Builder
    {
        return static::where('entity', self::SERVICE)
            ->where(fn ($query) => $query->whereNull('product_id')->orWhere('product_id', $productId))
            ->ordered();
    }

    /** @return list<string> The dropdown's choices, one per line. */
    public function choices(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->options))));
    }

    /** How a saved value reads on a page. */
    public function display(?string $value): string
    {
        return match (true) {
            $this->type === self::CHECKBOX => $value === '1' ? 'Yes' : 'No',
            blank($value) => '—',
            default => $value,
        };
    }

    /**
     * Validation rules for these fields under custom_fields[id].
     *
     * @param  Collection<int, self>  $fields
     * @return array<string, list<mixed>>
     */
    public static function rules(Collection $fields): array
    {
        return $fields->mapWithKeys(fn (self $field) => ["custom_fields.{$field->id}" => array_merge(
            [$field->required ? 'required' : 'nullable'],
            match ($field->type) {
                'dropdown' => [Rule::in($field->choices())],
                // A required checkbox has to be ticked.
                self::CHECKBOX => [$field->required ? 'accepted' : 'in:0,1'],
                'textarea' => ['string', 'max:5000'],
                default => ['string', 'max:1000'],
            },
        )])->all();
    }

    /** @return array<string, string> Attribute names for error messages. */
    public static function attributeNames(Collection $fields): array
    {
        return $fields->mapWithKeys(fn (self $field) => ["custom_fields.{$field->id}" => $field->name])->all();
    }

    /**
     * The saved values with these fields replaced from the request input.
     * Values of fields not in $fields (such as admin-only fields when a
     * client saves) are kept.
     *
     * @param  Collection<int, self>  $fields
     * @param  array<int|string, mixed>  $input
     * @param  array<int|string, string>|null  $saved
     * @return array<string, string>|null
     */
    public static function merge(Collection $fields, array $input, ?array $saved): ?array
    {
        $values = collect($saved ?? [])->mapWithKeys(fn ($value, $id) => [(string) $id => (string) $value]);

        foreach ($fields as $field) {
            $value = $field->type === self::CHECKBOX
                ? (($input[$field->id] ?? '0') === '1' ? '1' : '')
                : trim((string) ($input[$field->id] ?? ''));

            $value === '' ? $values->forget((string) $field->id) : $values->put((string) $field->id, $value);
        }

        return $values->isEmpty() ? null : $values->all();
    }

    /**
     * @param  Collection<int, self>  $fields
     * @param  array<int|string, string>  $values
     * @return array<string, string>
     */
    public static function byName(Collection $fields, array $values): array
    {
        return $fields->mapWithKeys(fn (self $field) => [$field->name => (string) ($values[$field->id] ?? '')])->all();
    }
}
