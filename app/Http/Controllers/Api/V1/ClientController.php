<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\VatNumbers;
use App\Enums\ClientStatus;
use App\Events\ClientCreated;
use App\Http\Resources\ClientResource;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CustomField;
use App\Support\ClientSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Clients through the REST API. The list takes the same filters as the
 * admin clients search; an update changes only the fields sent.
 */
class ClientController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $clients = ClientSearch::fromRequest($request)->apply(Client::query())
            ->orderBy('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ClientResource::collection($clients);
    }

    public function show(Client $client): ClientResource
    {
        return ClientResource::make($client);
    }

    public function store(Request $request): JsonResponse
    {
        $client = new Client($this->validated($request));
        if ($request->boolean('marketing_emails')) {
            $client->setMarketingConsent(true);
        }
        $client->save();
        Activity::record('Created client', $client);
        ClientCreated::dispatch($client);

        return ClientResource::make($client->refresh())->response()->setStatusCode(201);
    }

    public function update(Request $request, Client $client): ClientResource
    {
        $client->fill($this->validated($request, $client));
        if ($request->has('marketing_emails') && $client->setMarketingConsent($request->boolean('marketing_emails'))) {
            Activity::record($client->marketing_emails ? 'Agreed to marketing emails (set through the API)' : 'Opted out of marketing emails (set through the API)', $client);
        }
        // A new address hasn't been confirmed by the client yet.
        if ($client->isDirty('email')) {
            $client->email_verified_at = null;
        }
        $client->save();
        Activity::record('Updated client details', $client, ['changed' => array_keys(Arr::except($client->getChanges(), 'email_verified_at'))]);

        return ClientResource::make($client);
    }

    /**
     * The admin client form's rules, though a new client's status is
     * optional. On an update every field is optional, and only the custom
     * fields sent are checked and changed.
     */
    private function validated(Request $request, ?Client $client = null): array
    {
        $fields = CustomField::forClients()->get();
        if ($client !== null) {
            $sent = array_map('intval', array_keys((array) $request->input('custom_fields', [])));
            $fields = $fields->filter(fn (CustomField $field) => in_array($field->id, $sent, true));
        }

        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('clients')->ignore($client), ClientContact::loginEmailRule()],
            'phone' => ['nullable', 'string', 'max:50'],
            'address1' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'status' => [$client ? 'required' : 'nullable', Rule::enum(ClientStatus::class)],
            'notes' => ['nullable', 'string'],
            'tax_exempt' => ['boolean'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'marketing_emails' => ['boolean'],
            'client_group_id' => ['nullable', 'integer', Rule::exists('client_groups', 'id')],
            'custom_fields' => ['array'],
        ];
        if ($client !== null) {
            $rules = array_map(fn (array $rule) => ['sometimes', ...$rule], $rules);
        }

        $data = $request->validate($rules + CustomField::rules($fields), [], CustomField::attributeNames($fields));
        $data = Arr::except($data, ['marketing_emails', 'custom_fields']);

        if (array_key_exists('tax_id', $data)) {
            $data = [...$data, ...app(VatNumbers::class)->attributes($data['tax_id'], $client)];
        }
        if (array_key_exists('tax_exempt', $data)) {
            $data['tax_exempt'] = $request->boolean('tax_exempt');
        }
        if ($client === null || $fields->isNotEmpty()) {
            $data['custom_fields'] = CustomField::merge($fields, (array) $request->input('custom_fields', []), $client?->custom_fields);
        }

        // A new client sent without a status is active.
        if (($data['status'] ?? null) === null) {
            unset($data['status']);
        }

        return $data;
    }
}
