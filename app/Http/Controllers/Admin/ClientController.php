<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Events\ClientCreated;
use App\Http\Controllers\Controller;
use App\Http\Controllers\MarketingUnsubscribeController;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientGroup;
use App\Models\Currency;
use App\Models\CustomField;
use App\Models\InvoiceItem;
use App\Models\Tld;
use App\Support\ClientSearch;
use App\Support\Csv;
use App\Support\Language;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    /** The client page's side menu: groups of sections, each shown one at a time. */
    public const SECTIONS = [
        'Account' => ['summary' => 'Summary', 'profile' => 'Profile', 'contacts' => 'Contacts', 'security' => 'Login and privacy'],
        'Products' => ['services' => 'Services', 'domains' => 'Domains', 'orders' => 'Orders'],
        'Billing' => ['invoices' => 'Invoices', 'quotes' => 'Quotes', 'credit' => 'Credit', 'payment-methods' => 'Payment methods'],
        'Support' => ['tickets' => 'Tickets', 'emails' => 'Emails'],
        'Staff' => ['notes' => 'Notes', 'todos' => 'To-dos'],
        'History' => ['activity' => 'Activity log'],
    ];

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
        $client->sms_notifications = $request->boolean('sms_notifications');
        $client->save();
        Activity::record('Created client', $client);
        ClientCreated::dispatch($client);

        return redirect()->route('admin.clients.show', $client)->with('status', 'Client created.');
    }

    public function show(Request $request, Client $client): View|RedirectResponse
    {
        // The service picker on a service page sends the chosen service here.
        if ($request->filled('service') && $service = $client->services()->find($request->integer('service'))) {
            return redirect()->route('admin.services.show', $service);
        }

        $client->load([
            'services' => fn ($q) => $q->with('product')->latest(),
            'invoices' => fn ($q) => $q->withSum('transactions as paid_amount', 'amount')
                ->withExists(['items as is_funds' => fn ($items) => $items->whereIn('type', InvoiceItem::FUNDS_TYPES)])
                ->latest('issue_date')->latest('id'),
            'domains' => fn ($q) => $q->orderBy('name'),
            'quotes' => fn ($q) => $q->latest('issue_date')->latest('id'),
        ]);

        $activity = Activity::where('client_id', $client->id)->latest('created_at')->latest('id')->limit(20)->get();

        $credit = $client->creditEntries()->with(['invoice', 'user'])->latest('id')->limit(25)->get();

        $tickets = $client->tickets()->listed()->with(['department', 'assignee', 'tags'])->latest('last_reply_at')->limit(10)->get()->each->setRelation('client', $client);

        $emails = $client->emailLogs()->latest()->latest('id')->limit(10)->get();

        $orders = $client->orders()->with(['invoice', 'services.product'])->latest('id')->limit(5)->get()->each->setRelation('client', $client);

        $fields = CustomField::forClients()->get();

        $logins = $client->loginAttempts()->with('account')->latest('created_at')->latest('id')->limit(5)->get();

        $client->load(['group', 'clientNotes.user', 'paymentMethods']);

        $todos = $client->todos()->with('user')->orderByRaw("status = 'done'")->orderByRaw('due_on is null')->orderBy('due_on')->latest('id')->limit(10)->get();

        ['sections' => $sections, 'counts' => $counts] = self::frame($client);
        $section = collect($sections)->collapse()->has($request->query('tab')) ? $request->query('tab') : 'summary';

        // Add funds and mass payment invoices aren't owed on top of the rest.
        $unpaid = $client->invoices->where('status', InvoiceStatus::Unpaid)->reject->is_funds;
        $activeServices = $client->services->where('status', ServiceStatus::Active);
        $stats = [
            'owed' => $unpaid->sum(fn ($invoice) => $invoice->total - (int) $invoice->paid_amount),
            'unpaid' => $unpaid->count(),
            'overdue' => $unpaid->filter(fn ($invoice) => $invoice->due_date?->isPast() && ! $invoice->due_date->isToday())->count(),
            'activeServices' => $activeServices->count(),
            'nextDue' => $activeServices->pluck('next_due_date')->filter()->min(),
            'openTickets' => $client->tickets()->listed()->where('status', '!=', TicketStatus::Closed)->count(),
            'awaitingReply' => $client->tickets()->listed()->awaitingStaff()->count(),
        ];

        return view('admin.clients.show', compact('client', 'activity', 'credit', 'tickets', 'emails', 'fields', 'orders', 'logins', 'todos', 'sections', 'section', 'stats', 'counts'));
    }

    /**
     * The client page's section menu and the counts beside it, shared with
     * pages shown inside the client page, such as one of their services.
     *
     * @return array{sections: array<string, array<string, string>>, counts: array<string, int>}
     */
    public static function frame(Client $client): array
    {
        $sections = self::SECTIONS;
        if (! $client->domains()->exists() && ! Tld::exists()) {
            unset($sections['Products']['domains']);
        }

        return [
            'sections' => $sections,
            'counts' => [
                'contacts' => $client->contacts()->count(),
                'services' => $client->services()->count(),
                'domains' => $client->domains()->count(),
                'orders' => $client->orders()->count(),
                'invoices' => $client->invoices()->count(),
                'quotes' => $client->quotes()->count(),
                'payment-methods' => $client->paymentMethods()->count(),
                'tickets' => $client->tickets()->listed()->count(),
                'emails' => $client->emailLogs()->count(),
                'notes' => $client->clientNotes()->count(),
                'todos' => $client->todos()->unfinished()->count(),
            ],
        ];
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
        $client->sms_notifications = $request->boolean('sms_notifications');
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
            'language' => ['nullable', Rule::in(Language::all())],
            'currency' => ['nullable', Rule::in(array_filter([...Currency::codes(), $client?->currency]))],
        ] + CustomField::rules($fields), [], CustomField::attributeNames($fields));

        if (blank($data['currency'] ?? null)) {
            unset($data['currency']);
        } elseif ($client !== null && $data['currency'] !== $client->currency && $client->currencyLocked()) {
            throw ValidationException::withMessages(['currency' => "{$client->fullName()} already has invoices, payments or credit in {$client->currency}, so their currency can't be changed. Add a new client account for billing in {$data['currency']}."]);
        }

        return [
            ...$data,
            'client_group_id' => $data['client_group_id'] ?? null,
            'language' => $data['language'] ?? null,
            'tax_exempt' => $request->boolean('tax_exempt'),
            'custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), $client?->custom_fields),
        ];
    }
}
