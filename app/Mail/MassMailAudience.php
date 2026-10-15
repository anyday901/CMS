<?php

namespace App\Mail;

use App\Enums\ClientStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Who a mass mail goes to: clients with an email address picked by status,
 * group, country, currency and the products they have. Marketing emails
 * only ever go to clients who agreed to them.
 */
class MassMailAudience
{
    /** The client group filter's value for clients in no group. */
    public const NO_GROUP = 'none';

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'statuses' => ['required', 'array', 'min:1'],
            'statuses.*' => [Rule::enum(ClientStatus::class)],
            'group' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'product_ids' => ['array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'service_statuses' => ['array'],
            'service_statuses.*' => [Rule::enum(ServiceStatus::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Client>
     */
    public static function query(array $filters, bool $marketing): Builder
    {
        $group = (string) ($filters['group'] ?? '');
        $products = array_map('intval', (array) ($filters['product_ids'] ?? []));
        $serviceStatuses = (array) ($filters['service_statuses'] ?? []) ?: [ServiceStatus::Active->value];

        return Client::query()
            ->whereNotNull('email')->where('email', '!=', '')
            ->whereNull('anonymised_at')
            ->whereIn('status', (array) ($filters['statuses'] ?? [ClientStatus::Active->value]))
            ->when($marketing, fn ($q) => $q->where('marketing_emails', true))
            ->when($group === self::NO_GROUP, fn ($q) => $q->whereNull('client_group_id'))
            ->when(ctype_digit($group), fn ($q) => $q->where('client_group_id', (int) $group))
            ->when(filled($filters['country'] ?? null), fn ($q) => $q->where('country', strtoupper($filters['country'])))
            ->when(filled($filters['currency'] ?? null), fn ($q) => $q->where('currency', strtoupper($filters['currency'])))
            ->when($products !== [], fn ($q) => $q->whereHas('services', fn ($s) => $s->whereIn('product_id', $products)->whereIn('status', $serviceStatuses)));
    }
}
