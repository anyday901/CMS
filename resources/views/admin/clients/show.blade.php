@php
    use App\Support\Money;
    $panel = fn (string $key) => 'id="section-'.$key.'" data-section="'.$key.'"'.($section === $key ? '' : ' hidden');
    $navOn = 'bg-brand-100 font-semibold text-brand-700';
    $navOff = 'text-ink-700 hover:bg-ink-100';
    $address = collect([$client->address1, $client->address2, $client->city, $client->state, $client->postcode, $client->country])->filter()->join(', ');
    $taxName = match (true) {
        (bool) $client->tax_exempt => 'Exempt',
        App\Billing\VatNumbers::reverseCharged($client) => 'Reverse charge',
        default => collect([App\Models\TaxRule::forClient($client)?->name, App\Models\TaxRule::forClient($client, 2)?->name])->filter()->join(' + ') ?: 'None',
    };
    $taxId = $client->tax_id ? $client->tax_id.(App\Billing\VatNumbers::prefix($client->tax_id) ? ($client->tax_id_verified_at ? ' (VIES verified)' : ' (not verified)') : '') : null;
@endphp
<x-admin-layout :title="$client->fullName()">
    @include('admin.clients._header')

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-ink-200 bg-white px-4 py-3">
            <div class="text-sm text-ink-500">Credit balance</div>
            <div class="font-display text-2xl font-extrabold">{{ Money::format($client->credit_balance, $client->currency) }}</div>
        </div>
        <a href="{{ route('admin.clients.show', [$client, 'tab' => 'invoices']) }}" data-section-link="invoices" class="rounded-lg border border-ink-200 bg-white px-4 py-3 hover:border-brand-300">
            <div class="text-sm text-ink-500">Owed now</div>
            <div class="font-display text-2xl font-extrabold">{{ Money::format($stats['owed'], $client->currency) }}</div>
            <div class="text-xs {{ $stats['overdue'] ? 'text-red-700' : 'text-ink-500' }}">{{ trans_choice(':count unpaid invoice|:count unpaid invoices', $stats['unpaid']) }}@if ($stats['overdue']), {{ $stats['overdue'] }} overdue @endif</div>
        </a>
        <a href="{{ route('admin.clients.show', [$client, 'tab' => 'services']) }}" data-section-link="services" class="rounded-lg border border-ink-200 bg-white px-4 py-3 hover:border-brand-300">
            <div class="text-sm text-ink-500">Active services</div>
            <div class="font-display text-2xl font-extrabold">{{ $stats['activeServices'] }}</div>
            <div class="text-xs text-ink-500">{{ $stats['nextDue'] ? 'Next due '.$stats['nextDue']->format('M j, Y') : 'Nothing due' }}</div>
        </a>
        <a href="{{ route('admin.clients.show', [$client, 'tab' => 'tickets']) }}" data-section-link="tickets" class="rounded-lg border border-ink-200 bg-white px-4 py-3 hover:border-brand-300">
            <div class="text-sm text-ink-500">Open tickets</div>
            <div class="font-display text-2xl font-extrabold">{{ $stats['openTickets'] }}</div>
            <div class="text-xs {{ $stats['awaitingReply'] ? 'text-amber-800' : 'text-ink-500' }}">{{ $stats['awaitingReply'] ? $stats['awaitingReply'].' awaiting your reply' : 'None waiting on you' }}</div>
        </a>
    </div>

    <div class="flex flex-col gap-6 md:flex-row md:items-start">
        @include('admin.clients._nav')

        <div class="min-w-0 grow">
        <section {!! $panel('summary') !!}>
            <div class="mb-6 grid gap-4 md:grid-cols-2">
            <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <h2 class="mb-3 text-base font-semibold">Contact details</h2>
                <dl class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2">
                    <dt class="text-ink-500">Email</dt><dd class="break-words">{{ $client->email }}</dd>
                    <dt class="text-ink-500">Phone</dt><dd>{{ $client->phone ?: '—' }}</dd>
                    <dt class="text-ink-500">Company</dt><dd>{{ $client->company ?: '—' }}</dd>
                    <dt class="text-ink-500">Tax ID</dt><dd class="break-words">{{ $taxId ?: '—' }}</dd>
                    <dt class="text-ink-500">Address</dt><dd>{{ $address ?: '—' }}</dd>
                </dl>
            </div>
            <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <h2 class="mb-3 text-base font-semibold">Account</h2>
                <dl class="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-2">
                    <dt class="text-ink-500">Client since</dt><dd>{{ $client->created_at->format('M j, Y') }}</dd>
                    <dt class="text-ink-500">Group</dt><dd>{{ $client->group?->name ?? 'None' }}</dd>
                    @include('admin.clients._credit-limit')
                    <dt class="text-ink-500">Tax</dt><dd>{{ $taxName }}</dd>
                    <dt class="text-ink-500">Two-factor</dt><dd>{{ $client->hasTwoFactor() ? 'On' : 'Off' }}</dd>
                    <dt class="text-ink-500">Marketing emails</dt><dd>{{ $client->marketing_emails ? 'Agreed' : 'Not agreed' }}</dd>
                </dl>
            </div>
            </div>

            <div class="mb-3 flex items-center gap-3">
            <h2 class="text-lg font-semibold">Recent emails</h2>
            <a href="{{ route('admin.clients.show', [$client, 'tab' => 'emails']) }}" data-section-link="emails" class="text-sm text-brand-600">All emails</a>
            </div>
            <div class="mb-6">
            @if ($emails->isEmpty())
                <p class="text-sm text-ink-500">No emails sent yet.</p>
            @else
                @include('admin.emails._table', ['emails' => $emails->take(5)])
            @endif
            </div>

            <div class="mb-3 flex items-center gap-3">
            <h2 class="text-lg font-semibold">Recent activity</h2>
            <a href="{{ route('admin.clients.show', [$client, 'tab' => 'activity']) }}" data-section-link="activity" class="text-sm text-brand-600">Activity log</a>
            </div>
            @if ($activity->isEmpty())
            <p class="text-sm text-ink-500">Nothing logged yet.</p>
            @else
            @include('admin.activity._list', ['entries' => $activity->take(5), 'showClient' => false])
            @endif
        </section>

        <section {!! $panel('profile') !!}>
            <h2 class="mb-3 text-lg font-semibold">Profile</h2>
            <div class="mb-6 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm md:grid-cols-3">
                <div><div class="text-ink-500">Email</div>{{ $client->email }}
                    @if ($client->isAnonymised())
                        <span class="text-ink-500">(anonymised {{ $client->anonymised_at->format('M j, Y') }})</span>
                    @elseif ($client->hasVerifiedEmail())
                        <span class="text-green-700">· verified</span>
                    @else
                        <span class="text-amber-700">· not verified</span>
                    @endif
                </div>
                <div><div class="text-ink-500">Company</div>{{ $client->company ?: '—' }}</div>
                <div><div class="text-ink-500">Tax ID</div>{{ $taxId ?: '—' }}</div>
                <div><div class="text-ink-500">Phone</div>{{ $client->phone ?: '—' }}</div>
                <div><div class="text-ink-500">Address</div>{{ $address ?: '—' }}</div>
                <div><div class="text-ink-500">Credit balance</div>{{ Money::format($client->credit_balance, $client->currency) }}</div>
                <div><div class="text-ink-500">Tax</div>{{ $taxName }}</div>
                <div><div class="text-ink-500">Client since</div>{{ $client->created_at->format('M j, Y') }}</div>
                <div><div class="text-ink-500">Group</div>{{ $client->group?->name ?? 'None' }}</div>
                <div><div class="text-ink-500">Marketing emails</div>
                    @if ($client->marketing_emails)<span class="text-green-700">Agreed</span>@else Not agreed @endif
                    @if ($client->marketing_consent_at)<span class="text-ink-500">· {{ $client->marketing_consent_at->format('M j, Y') }}</span>@endif
                </div>
                @include('custom-fields._values', ['fields' => $fields, 'values' => $client->custom_fields ?? [], 'wide' => 'md:col-span-3', 'showAdminOnly' => true])
                @if ($client->notes)<div class="md:col-span-3"><div class="text-ink-500">Profile notes</div>{!! nl2br(e($client->notes)) !!}</div>@endif
            </div>

            @include('admin.affiliates._client')
        </section>

        <section {!! $panel('contacts') !!}>
            <div class="mb-3 flex items-center gap-3">
                <h2 class="text-lg font-semibold">Contacts</h2>
                @can('manage-clients')<a href="{{ route('admin.contacts.create', $client) }}" class="text-sm text-brand-600">Add contact</a>@endcan
            </div>
            <div class="mb-8">
                @include('contacts._list', ['contacts' => $client->contacts, 'editRoute' => auth()->user()->can('manage-clients') ? 'admin.contacts.edit' : null, 'destroyRoute' => 'admin.contacts.destroy', 'inviteRoute' => 'admin.contacts.invite', 'twoFactorRoute' => 'admin.contacts.two-factor.reset'])
            </div>
        </section>

        <section {!! $panel('security') !!}>
            <h2 id="security" class="mb-3 text-lg font-semibold">Login and privacy</h2>
            <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <span class="grow text-ink-600">
                    Two-factor authentication is <strong>{{ $client->hasTwoFactor() ? 'on' : 'off' }}</strong>.
                    @unless ($client->hasVerifiedEmail() || $client->isAnonymised()) The email address isn't verified yet. @endunless
                </span>
                @can('manage-clients')
                    @unless ($client->hasVerifiedEmail() || $client->isAnonymised())
                        <form method="POST" action="{{ route('admin.clients.verification-link', $client) }}">@csrf
                            <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Email verification link</button>
                        </form>
                        <form method="POST" action="{{ route('admin.clients.verify-email', $client) }}">@csrf
                            <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Mark verified</button>
                        </form>
                    @endunless
                    @if ($client->hasTwoFactor())
                        <form method="POST" action="{{ route('admin.clients.two-factor.reset', $client) }}" onsubmit="return confirm('Turn off two-factor authentication for this client? Do this only once you are sure who you are talking to.')">@csrf @method('DELETE')
                            <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Turn off two-factor</button>
                        </form>
                    @endif
                    <a href="{{ route('admin.clients.export', $client) }}" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Export personal data</a>
                @endcan
                @can('manage-settings')
                    <a href="{{ route('admin.security.show', ['client' => $client->id]) }}#logins" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">All logins</a>
                @endcan
            </div>
            <div class="mb-4">@include('security._logins', ['logins' => $logins, 'showWho' => true])</div>
            @can('manage-clients')
                @if ($client->status === App\Enums\ClientStatus::Closed && ! $client->isAnonymised())
                    <details class="mb-8 rounded-lg border border-red-200 bg-red-50 p-4 text-sm" @if ($errors->hasAny(['confirm', 'anonymise'])) open @endif>
                        <summary class="cursor-pointer font-medium text-red-800">Anonymise this client</summary>
                        <form method="POST" action="{{ route('admin.clients.anonymise', $client) }}" class="mt-3 space-y-3">
                            @csrf
                            <p class="text-red-900">For a right-to-erasure request. This removes the client's name, email, phone, address, notes and custom fields, deletes their contacts, emails and login history, and blanks what was said in their tickets. Invoices, payments and services are kept for your tax records, without their personal details. It can't be undone, so export their data first if they asked for a copy.</p>
                            <label class="flex items-center gap-2"><input type="checkbox" name="confirm" value="1"> I understand this can't be undone</label>
                            <button class="rounded-md bg-red-700 px-3 py-1.5 font-medium text-white hover:bg-red-600">Anonymise</button>
                        </form>
                    </details>
                @endif
            @endcan
        </section>

        <section {!! $panel('services') !!}>
            <h2 class="mb-3 text-lg font-semibold">Services</h2>
            @if ($client->services->isEmpty())
                <p class="mb-8 text-sm text-ink-500">No services yet.</p>
            @else
                <div class="mb-8 overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Service</th><th class="px-4 py-2">Cycle</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2">Next due</th><th class="px-4 py-2">Status</th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($client->services as $service)
                                <tr>
                                    <td class="px-4 py-2"><a href="{{ route('admin.services.show', $service) }}" class="text-brand-600">{{ $service->description() }}</a></td>
                                    <td class="px-4 py-2">{{ $service->billing_cycle->label() }}</td>
                                    <td class="px-4 py-2 text-right">{{ Money::format($service->recurring_amount, $client->currency) }}</td>
                                    <td class="px-4 py-2">{{ $service->next_due_date?->format('M j, Y') ?? '—' }}</td>
                                    <td class="px-4 py-2"><x-status-badge :status="$service->status" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section {!! $panel('domains') !!}>
            @if ($client->domains->isNotEmpty() || App\Models\Tld::exists())
                <div class="mb-3 flex items-center gap-3">
                    <h2 class="text-lg font-semibold">Domains</h2>
                    @can('manage-clients')<a href="{{ route('admin.domains.create', $client) }}" class="text-sm text-brand-600">Add domain</a>@endcan
                </div>
                @if ($client->domains->isEmpty())
                    <p class="mb-8 text-sm text-ink-500">No domains yet.</p>
                @else
                    <div class="mb-8 overflow-x-auto rounded-lg border border-ink-200 bg-white">
                        <table class="min-w-full text-sm">
                            <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Domain</th><th class="px-4 py-2">Expires</th><th class="px-4 py-2 text-right">Renewal</th><th class="px-4 py-2">Auto-renew</th><th class="px-4 py-2">Status</th></tr></thead>
                            <tbody class="divide-y divide-ink-100">
                                @foreach ($client->domains as $domain)
                                    <tr>
                                        <td class="px-4 py-2"><a href="{{ route('admin.domains.show', $domain) }}" class="text-brand-600">{{ $domain->name }}</a></td>
                                        <td class="px-4 py-2">{{ $domain->expiry_date?->format('M j, Y') ?? '—' }}</td>
                                        <td class="px-4 py-2 text-right">{{ Money::format($domain->recurring_amount, $client->currency) }} / {{ $domain->years }} yr</td>
                                        <td class="px-4 py-2">{{ $domain->auto_renew ? 'On' : 'Off' }}</td>
                                        <td class="px-4 py-2"><x-status-badge :status="$domain->status">{{ $domain->status->label() }}</x-status-badge></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </section>

        <section {!! $panel('orders') !!}>
            <h2 class="mb-3 text-lg font-semibold">Orders</h2>
            @if ($orders->isEmpty())
            <p class="text-sm text-ink-500">No orders yet.</p>
            @else
            @include('admin.orders._table')
            <a href="{{ route('admin.orders.index', ['client' => $client->id]) }}" class="mt-2 inline-block text-sm text-brand-600">View all orders</a>
            @endif
        </section>

        <section {!! $panel('invoices') !!}>
            <h2 class="mb-3 text-lg font-semibold">Invoices</h2>
            @if ($client->invoices->isEmpty())
                <p class="text-sm text-ink-500">No invoices yet.</p>
            @else
                @php $mergeable = auth()->user()->can('manage-billing') && $client->invoices->where('status', App\Enums\InvoiceStatus::Unpaid)->reject->is_funds->count() >= 2; @endphp
                @if ($mergeable)
                    @include('admin.invoices._merge-form')
                @endif
                @include('admin.invoices._table', ['invoices' => $client->invoices->each->setRelation('client', $client), 'mergeable' => $mergeable])
            @endif
            @if ($client->creditNotes->isNotEmpty())
                <h3 class="mb-2 mt-6 font-semibold">Credit notes</h3>
                @include('admin.credit-notes._table', ['creditNotes' => $client->creditNotes])
            @endif
        </section>

        <section {!! $panel('billable-items') !!}>
            @include('admin.clients._billable_items')
        </section>

        <section {!! $panel('quotes') !!}>
            <div class="mb-3 flex items-center gap-3">
                <h2 class="text-lg font-semibold">Quotes</h2>
                @can('manage-billing')
                    <a href="{{ route('admin.quotes.create', $client) }}" class="ml-auto rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">New quote</a>
                @endcan
            </div>
            @if ($client->quotes->isEmpty())
                <p class="text-sm text-ink-500">No quotes yet.</p>
            @else
                @include('admin.quotes._table', ['quotes' => $client->quotes->each->setRelation('client', $client), 'hideClient' => true])
            @endif
        </section>

        <section {!! $panel('credit') !!}>
            <h2 class="mb-3 text-lg font-semibold">Credit <span class="text-base font-normal text-ink-500">{{ Money::format($client->credit_balance, $client->currency) }} available</span></h2>
            @can('manage-billing')
                <details class="mb-4 text-sm" @if ($errors->hasAny(['direction', 'amount', 'reason'])) open @endif>
                    <summary class="cursor-pointer text-brand-600">Adjust credit</summary>
                    <form method="POST" action="{{ route('admin.clients.credit', $client) }}" class="mt-2 flex flex-wrap items-end gap-2">
                        @csrf
                        <label class="block">Change
                            <select name="direction" class="mt-1 block rounded-md px-2 py-1 ring-1 ring-ink-300">
                                <option value="add" @selected(old('direction') !== 'remove')>Add credit</option>
                                <option value="remove" @selected(old('direction') === 'remove')>Remove credit</option>
                            </select>
                        </label>
                        <label class="block">Amount
                            <input name="amount" value="{{ old('amount') }}" required placeholder="0.00" class="mt-1 block w-28 rounded-md px-2 py-1 ring-1 ring-ink-300">
                        </label>
                        <label class="block">Reason
                            <input name="reason" value="{{ old('reason') }}" required maxlength="255" placeholder="Goodwill credit for outage" class="mt-1 block w-72 rounded-md px-2 py-1 ring-1 ring-ink-300">
                        </label>
                        <span class="basis-full text-ink-500">The client sees the reason in their credit history.</span>
                        <button class="rounded-md px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Save</button>
                    </form>
                </details>
            @endcan
            @if ($credit->isEmpty())
                <p class="text-sm text-ink-500">No credit history yet.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">What</th><th class="px-4 py-2">By</th><th class="px-4 py-2 text-right">Change</th><th class="px-4 py-2 text-right">Balance</th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($credit as $entry)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-2">{{ $entry->created_at->format('M j, Y') }}</td>
                                    <td class="px-4 py-2">@if ($entry->invoice)<a href="{{ route('admin.invoices.show', $entry->invoice) }}" class="text-brand-600">{{ $entry->description }}</a>@else{{ $entry->description }}@endif</td>
                                    <td class="px-4 py-2 text-ink-500">{{ $entry->user?->name ?? 'System' }}</td>
                                    <td class="px-4 py-2 text-right {{ $entry->amount < 0 ? 'text-red-700' : 'text-green-700' }}">{{ $entry->amount > 0 ? '+' : '' }}{{ Money::format($entry->amount, $client->currency) }}</td>
                                    <td class="px-4 py-2 text-right">{{ Money::format($entry->balance_after, $client->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section {!! $panel('payment-methods') !!}>
            <h2 class="mb-3 text-lg font-semibold">Payment methods</h2>
            @if ($client->paymentMethods->isEmpty())
                <p class="mb-4 text-sm text-ink-500">No saved cards. Clients save cards in the client area under Invoices &gt; Payment methods, or when they pay.</p>
            @else
                <div class="mb-4 rounded-lg border border-ink-200 bg-white text-sm">
                    @foreach ($client->paymentMethods as $method)
                        <div class="flex flex-wrap items-center gap-3 border-b border-ink-100 px-4 py-2 last:border-b-0">
                            <div class="grow">
                                <span class="font-medium">{{ $method->label() }}</span>
                                @if ($method->expiry())<span class="{{ $method->isExpired() ? 'text-red-700' : 'text-ink-500' }}">· {{ $method->isExpired() ? 'expired' : 'expires' }} {{ $method->expiry() }}</span>@endif
                                <span class="text-ink-500">· {{ ucfirst($method->gateway) }}</span>
                                @if ($method->is_default)<span class="ml-1 rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-700">Default</span>@endif
                            </div>
                            @can('manage-billing')
                                <form method="POST" action="{{ route('admin.payment-methods.destroy', $method) }}" onsubmit="return confirm('Remove this card? It is removed at the gateway too.')">
                                    @csrf @method('DELETE')
                                    <button class="rounded-md px-3 py-1 text-red-700 ring-1 ring-ink-300 hover:bg-red-50">Remove</button>
                                </form>
                            @endcan
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="flex flex-wrap items-center gap-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <span class="grow text-ink-600">Automatic charging is <strong>{{ $client->auto_charge ? 'on' : 'off' }}</strong>: @if ($client->auto_charge) unpaid invoices are charged to the default card when they fall due (see <a href="{{ route('admin.automation.edit') }}" class="text-brand-600">Automation settings</a>).@else the client pays each invoice themselves.@endif</span>
                @can('manage-billing')
                    <form method="POST" action="{{ route('admin.clients.auto-charge', $client) }}">
                        @csrf @method('PUT')
                        <input type="hidden" name="auto_charge" value="{{ $client->auto_charge ? 0 : 1 }}">
                        <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ $client->auto_charge ? 'Turn off' : 'Turn on' }}</button>
                    </form>
                @endcan
            </div>
        </section>

        <section {!! $panel('tickets') !!}>
            <h2 class="mb-3 text-lg font-semibold">Tickets</h2>
            @if ($tickets->isEmpty())
                <p class="text-sm text-ink-500">No tickets.</p>
            @else
                @include('admin.tickets._table', ['hideClient' => true])
            @endif
        </section>

        <section {!! $panel('emails') !!}>
            <h2 class="mb-3 text-lg font-semibold">Emails</h2>
            @if ($emails->isEmpty())
                <p class="text-sm text-ink-500">No emails sent yet.</p>
            @else
                @include('admin.emails._table')
            @endif
            @if ($counts['emails'] > $emails->count())
            <p class="mt-2 text-sm text-ink-500">Showing the latest {{ $emails->count() }} of {{ $counts['emails'] }}.</p>
            @endif
        </section>

        <section {!! $panel('notes') !!}>
            <h2 id="notes" class="mb-3 text-lg font-semibold">Notes</h2>
            <div class="mb-8 space-y-2">
                @foreach ($client->clientNotes as $note)
                    <div class="rounded-lg border bg-white p-4 text-sm {{ $note->pinned ? 'border-amber-300' : 'border-ink-200' }}">
                        <div class="mb-1 flex flex-wrap items-center gap-2 text-xs text-ink-500">
                            <span class="font-medium text-ink-700">{{ $note->user?->name ?? 'Staff' }}</span>
                            <span>{{ $note->created_at->format('M j, Y g:i A') }}@if ($note->updated_at->gt($note->created_at->addMinute())) · edited {{ $note->updated_at->format('M j, Y') }}@endif</span>
                            @if ($note->pinned)<span class="rounded-full bg-sun-300/60 px-2 py-0.5 font-semibold text-amber-900">Pinned</span>@endif
                        </div>
                        <div>{!! nl2br(e($note->body)) !!}</div>
                        @can('manage-clients')
                            <details class="mt-2">
                                <summary id="note-edit-{{ $note->id }}" class="cursor-pointer text-brand-600">Edit note</summary>
                                <form method="POST" action="{{ route('admin.client-notes.update', $note) }}" class="mt-2 space-y-2">
                                    @csrf @method('PUT')
                                    <textarea name="body" rows="3" required aria-labelledby="note-edit-{{ $note->id }}" class="w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ $note->body }}</textarea>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <label for="note-pinned-{{ $note->id }}" class="flex items-center gap-2"><input id="note-pinned-{{ $note->id }}" type="checkbox" name="pinned" value="1" @checked($note->pinned)> Pin to the top of the page</label>
                                        <button class="rounded-md px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Save</button>
                                        <button form="delete-note-{{ $note->id }}" class="text-red-600 hover:underline">Delete</button>
                                    </div>
                                </form>
                                <form id="delete-note-{{ $note->id }}" method="POST" action="{{ route('admin.client-notes.destroy', $note) }}" onsubmit="return confirm('Delete this note?')">@csrf @method('DELETE')</form>
                            </details>
                        @endcan
                    </div>
                @endforeach
                @can('manage-clients')
                    <form method="POST" action="{{ route('admin.client-notes.store', $client) }}" class="rounded-lg border border-dashed border-ink-300 bg-white p-4 text-sm">
                        @csrf
                        <label class="block">Add a note
                            <textarea name="body" rows="2" required placeholder="Only staff see notes." class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></textarea>
                        </label>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <label class="flex items-center gap-2"><input type="checkbox" name="pinned" value="1"> Pin to the top of the page</label>
                            <button class="rounded-full bg-brand-600 px-3 py-1 font-medium text-white hover:bg-brand-500">Add note</button>
                        </div>
                    </form>
                @elseif ($client->clientNotes->isEmpty())
                    <p class="text-sm text-ink-500">No notes.</p>
                @endcan
            </div>
        </section>

        <section {!! $panel('todos') !!}>
            <div id="todos" class="mb-3 flex items-center gap-3">
                <h2 class="text-lg font-semibold">To-dos</h2>
                <a href="{{ route('admin.todos.index', ['client' => $client->id, 'who' => 'all']) }}" class="text-sm text-brand-600">Add to-do</a>
            </div>
            <div class="mb-8">
                @if ($todos->isEmpty())
                    <p class="text-sm text-ink-500">Nothing to do for this client.</p>
                @else
                    @include('admin.todos._table', ['hideClient' => true])
                @endif
            </div>
        </section>

        <section {!! $panel('activity') !!}>
            <h2 class="mb-3 text-lg font-semibold">Recent activity</h2>
            @if ($activity->isEmpty())
                <p class="text-sm text-ink-500">Nothing logged yet.</p>
            @else
                @include('admin.activity._list', ['entries' => $activity, 'showClient' => false])
            @endif
        </section>
        </div>
    </div>

    <script>
        // Show one section at a time without reloading, keeping ?tab= in the address so a form sent from a section comes back to it.
        (() => {
            const on = @json(explode(' ', $navOn)), off = @json(explode(' ', $navOff));
            const links = document.querySelectorAll('[data-section-link]');
            const sections = document.querySelectorAll('[data-section]');
            const open = (key) => {
                sections.forEach((section) => { section.hidden = section.dataset.section !== key; });
                document.querySelectorAll('nav [data-section-link]').forEach((link) => {
                    const current = link.dataset.sectionLink === key;
                    link.classList.remove(...(current ? off : on));
                    link.classList.add(...(current ? on : off));
                    current ? link.setAttribute('aria-current', 'page') : link.removeAttribute('aria-current');
                });
            };
            links.forEach((link) => link.addEventListener('click', (event) => {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
                event.preventDefault();
                open(link.dataset.sectionLink);
                history.replaceState(null, '', link.href);
            }));
            // Links to a part of the page, such as #notes, open the section holding it.
            const showHash = () => {
                const target = location.hash && document.getElementById(decodeURIComponent(location.hash.slice(1)));
                const section = target?.closest('[data-section]');
                if (section) {
                    open(section.dataset.section);
                    target.scrollIntoView();
                }
            };
            window.addEventListener('hashchange', showHash);
            showHash();
        })();
    </script>
</x-admin-layout>
