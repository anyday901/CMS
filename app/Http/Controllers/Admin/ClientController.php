<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientStatus;
use App\Events\ClientCreated;
use App\Http\Controllers\Controller;
use App\Http\Controllers\MarketingUnsubscribeController;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientGroup;
use App\Models\CustomField;
use App\Support\ClientSearch;
use App\Support\Csv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $search = ClientSearch::fromRequest($request);

        $clients = $search->apply(Client::query())
            ->with('group')
            ->withCount('services')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.clients.index', [
            'clients' => $clients,
            'search' => $search,
            'closedCount' => $search->hidesClosed() ? $search->closedCount(Client::query()) : 0,
            'countries' => $this->distinct('country'),
            'currencies' => $this->distinct('currency'),
            'groups' => ClientGroup::orderBy('name')->get(),
        ]);
    }

    /**
     * The clients this search finds, as a CSV file, for a mailing list or a
     * spreadsheet. Each row has the client's own unsubscribe link, for
     * marketing emails sent from elsewhere.
     */
    public function export(Request $request): StreamedResponse
    {
        $search = ClientSearch::fromRequest($request);
        $query = $search->apply(Client::query())->with('group')->orderBy('id');
        Activity::record('Exported clients to CSV', null, ['filters' => $search->filters(), 'count' => (clone $query)->count()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'first_name', 'last_name', 'company', 'email', 'phone', 'country', 'status', 'group', 'marketing_emails', 'marketing_consent_at', 'unsubscribe_url', 'created_at'], escape: '');
            $query->chunk(500, function ($clients) use ($out) {
                foreach ($clients as $client) {
                    fputcsv($out, array_map(fn (mixed $value) => Csv::cell($value), [
                        $client->id, $client->first_name, $client->last_name, $client->company, $client->email, $client->phone,
                        $client->country, $client->status->value, $client->group?->name, $client->marketing_emails ? 'yes' : 'no',
                        $client->marketing_consent_at?->toIso8601String(), MarketingUnsubscribeController::url($client), $client->created_at?->toIso8601String(),
                    ]), escape: '');
                }
            });
            fclose($out);
        }, 'clients-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** The values clients have for a column, for the search's dropdowns. */
    private function distinct(string $column): array
    {
        return Client::query()->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column)->all();
    }

    public function create(): View
    {
        return view('admin.clients.form', ['client' => new Client, 'fields' => CustomField::forClients()->get(), 'groups' => ClientGroup::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = new Client($this->validated($request));
        if ($request->boolean('marketing_emails')) {
            $client->setMarketingConsent(true);
        }
        $client->save();
        Activity::record('Created client', $client);
        ClientCreated::dispatch($client);

        return redirect()->route('admin.clients.show', $client)->with('status', 'Client created.');
    }

    public function show(Client $client): View
    {
        $client->load([
            'services' => fn ($q) => $q->with('product')->latest(),
            'invoices' => fn ($q) => $q->latest('issue_date')->latest('id'),
        ]);

        $activity = Activity::where('client_id', $client->id)->latest('created_at')->latest('id')->limit(20)->get();

        $credit = $client->creditEntries()->with(['invoice', 'user'])->latest('id')->limit(25)->get();

        $tickets = $client->tickets()->listed()->with(['department', 'assignee', 'tags'])->latest('last_reply_at')->limit(10)->get()->each->setRelation('client', $client);

        $emails = $client->emailLogs()->latest('id')->limit(10)->get();

        $orders = $client->orders()->with(['invoice', 'services.product'])->latest('id')->limit(5)->get()->each->setRelation('client', $client);

        $fields = CustomField::forClients()->get();

        $logins = $client->loginAttempts()->with('account')->latest('created_at')->latest('id')->limit(5)->get();

        $client->load(['group', 'clientNotes.user']);

        $todos = $client->todos()->with('user')->orderByRaw("status = 'done'")->orderByRaw('due_on is null')->orderBy('due_on')->latest('id')->limit(10)->get();

        return view('admin.clients.show', compact('client', 'activity', 'credit', 'tickets', 'emails', 'fields', 'orders', 'logins', 'todos'));
    }

    public function edit(Client $client): View
    {
        return view('admin.clients.form', ['client' => $client, 'fields' => CustomField::forClients()->get(), 'groups' => ClientGroup::orderBy('name')->get()]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->fill($this->validated($request, $client));
        if ($client->setMarketingConsent($request->boolean('marketing_emails'))) {
            Activity::record($client->marketing_emails ? 'Agreed to marketing emails (set by staff)' : 'Opted out of marketing emails (set by staff)', $client);
        }
        // A new address hasn't been confirmed by the client yet.
        if ($client->isDirty('email')) {
            $client->email_verified_at = null;
        }
        $client->save();
        Activity::record('Updated client details', $client, ['changed' => array_keys(Arr::except($client->getChanges(), 'email_verified_at'))]);

        return redirect()->route('admin.clients.show', $client)->with('status', 'Client updated.');
    }

    public function sendPasswordLink(Client $client): RedirectResponse
    {
        $status = Password::broker('clients')->sendResetLink(['email' => $client->email]);
        Activity::record('Sent a portal password link', $client);

        return back()->with('status', $status === Password::RESET_LINK_SENT
            ? "Password setup link sent to {$client->email}."
            : __($status));
    }

    private function validated(Request $request, ?Client $client = null): array
    {
        $fields = CustomField::forClients()->get();

        $data = $request->validate([
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
            'status' => ['required', Rule::enum(ClientStatus::class)],
            'notes' => ['nullable', 'string'],
            'tax_exempt' => ['boolean'],
            'client_group_id' => ['nullable', 'integer', Rule::exists('client_groups', 'id')],
        ] + CustomField::rules($fields), [], CustomField::attributeNames($fields));

        return [
            ...$data,
            'client_group_id' => $data['client_group_id'] ?? null,
            'tax_exempt' => $request->boolean('tax_exempt'),
            'custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), $client?->custom_fields),
        ];
    }
}
