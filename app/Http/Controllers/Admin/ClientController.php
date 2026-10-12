<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\CustomField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $clients = Client::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('company', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->withCount('services')
            ->orderBy('last_name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.clients.index', compact('clients', 'search'));
    }

    public function create(): View
    {
        return view('admin.clients.form', ['client' => new Client, 'fields' => CustomField::forClients()->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = Client::create($this->validated($request));
        Activity::record('Created client', $client);

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

        $tickets = $client->tickets()->with(['department', 'assignee'])->latest('last_reply_at')->limit(10)->get()->each->setRelation('client', $client);

        $emails = $client->emailLogs()->latest('id')->limit(10)->get();

        $orders = $client->orders()->with(['invoice', 'services.product'])->latest('id')->limit(5)->get()->each->setRelation('client', $client);

        $fields = CustomField::forClients()->get();

        return view('admin.clients.show', compact('client', 'activity', 'credit', 'tickets', 'emails', 'fields', 'orders'));
    }

    public function edit(Client $client): View
    {
        return view('admin.clients.form', ['client' => $client, 'fields' => CustomField::forClients()->get()]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->update($this->validated($request, $client));
        Activity::record('Updated client details', $client, ['changed' => array_keys($client->getChanges())]);

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
            'email' => ['required', 'email', 'max:255', Rule::unique('clients')->ignore($client)],
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
        ] + CustomField::rules($fields), [], CustomField::attributeNames($fields));

        return [
            ...$data,
            'tax_exempt' => $request->boolean('tax_exempt'),
            'custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), $client?->custom_fields),
        ];
    }
}
