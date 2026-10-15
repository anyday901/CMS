@php use App\Support\Money; @endphp
<x-admin-layout :title="$client->fullName()">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $client->fullName() }}</h1>
        <x-status-badge :status="$client->status" />
        <x-client-group-badge :group="$client->group" />
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @can('manage-clients')
                <form method="POST" action="{{ route('admin.clients.password-link', $client) }}">@csrf
                    <button class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Email portal password link</button>
                </form>
                @if ($client->canLogIn())
                    <form method="POST" action="{{ route('admin.clients.login-as', $client) }}">@csrf
                        <button class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Log in as client</button>
                    </form>
                @endif
                <a href="{{ route('admin.clients.merge', $client) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Merge</a>
                <a href="{{ route('admin.clients.edit', $client) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Edit</a>
            @endcan
            @can('manage-billing')
                <a href="{{ route('admin.invoices.create', $client) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">New invoice</a>
            @endcan
            @can('manage-clients')
                <a href="{{ route('admin.services.create', $client) }}" class="rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">Add service</a>
            @endcan
        </div>
    </div>

    @foreach ($client->clientNotes->where('pinned', true) as $note)
        <div class="mb-3 flex gap-3 rounded-md border border-amber-200 bg-sun-300/30 px-4 py-3 text-sm text-amber-950">
            <span class="font-semibold">Note</span>
            <div class="grow">{!! nl2br(e($note->body)) !!}<span class="mt-1 block text-xs text-amber-800">{{ $note->user?->name ?? 'Staff' }}, {{ $note->created_at->format('M j, Y') }} · <a href="#notes" class="underline">all notes</a></span></div>
        </div>
    @endforeach

    <div class="mb-8 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm md:grid-cols-3">
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
        <div><div class="text-ink-500">Phone</div>{{ $client->phone ?: '—' }}</div>
        <div><div class="text-ink-500">Address</div>{{ collect([$client->address1, $client->address2, $client->city, $client->state, $client->postcode, $client->country])->filter()->join(', ') ?: '—' }}</div>
        <div><div class="text-ink-500">Credit balance</div>{{ Money::format($client->credit_balance, $client->currency) }}</div>
        <div><div class="text-ink-500">Tax</div>{{ $client->tax_exempt ? 'Exempt' : (App\Models\TaxRule::forClient($client)?->name ?? 'None') }}</div>
        <div><div class="text-ink-500">Client since</div>{{ $client->created_at->format('M j, Y') }}</div>
        <div><div class="text-ink-500">Group</div>{{ $client->group?->name ?? 'None' }}</div>
        <div><div class="text-ink-500">Marketing emails</div>
            @if ($client->marketing_emails)<span class="text-green-700">Agreed</span>@else Not agreed @endif
            @if ($client->marketing_consent_at)<span class="text-ink-500">· {{ $client->marketing_consent_at->format('M j, Y') }}</span>@endif
        </div>
        @include('custom-fields._values', ['fields' => $fields, 'values' => $client->custom_fields ?? [], 'wide' => 'md:col-span-3', 'showAdminOnly' => true])
        @if ($client->notes)<div class="md:col-span-3"><div class="text-ink-500">Profile notes</div>{!! nl2br(e($client->notes)) !!}</div>@endif
    </div>

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
                        <summary class="cursor-pointer text-brand-600">Edit</summary>
                        <form method="POST" action="{{ route('admin.client-notes.update', $note) }}" class="mt-2 space-y-2">
                            @csrf @method('PUT')
                            <textarea name="body" rows="3" required aria-label="Note" class="w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ $note->body }}</textarea>
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

    <div class="mb-3 flex items-center gap-3">
        <h2 class="text-lg font-semibold">Contacts</h2>
        @can('manage-clients')<a href="{{ route('admin.contacts.create', $client) }}" class="text-sm text-brand-600">Add contact</a>@endcan
    </div>
    <div class="mb-8">
        @include('contacts._list', ['contacts' => $client->contacts, 'editRoute' => auth()->user()->can('manage-clients') ? 'admin.contacts.edit' : null, 'destroyRoute' => 'admin.contacts.destroy', 'inviteRoute' => 'admin.contacts.invite', 'twoFactorRoute' => 'admin.contacts.two-factor.reset'])
    </div>

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

    @if ($orders->isNotEmpty())
        <h2 class="mb-3 text-lg font-semibold">Orders</h2>
        @include('admin.orders._table')
        <a href="{{ route('admin.orders.index', ['client' => $client->id]) }}" class="mb-8 mt-2 inline-block text-sm text-brand-600">View all orders</a>
    @endif

    <h2 class="mb-3 text-lg font-semibold">Invoices</h2>
    @if ($client->invoices->isEmpty())
        <p class="text-sm text-ink-500">No invoices yet.</p>
    @else
        @include('admin.invoices._table', ['invoices' => $client->invoices->each->setRelation('client', $client)])
    @endif

    <h2 class="mt-8 mb-3 text-lg font-semibold">Credit <span class="text-base font-normal text-ink-500">{{ Money::format($client->credit_balance, $client->currency) }} available</span></h2>
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

    <h2 class="mt-8 mb-3 text-lg font-semibold">Tickets</h2>
    @if ($tickets->isEmpty())
        <p class="text-sm text-ink-500">No tickets.</p>
    @else
        @include('admin.tickets._table', ['hideClient' => true])
    @endif

    <h2 id="security" class="mt-8 mb-3 text-lg font-semibold">Login and privacy</h2>
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

    <h2 class="mt-8 mb-3 text-lg font-semibold">Recent emails</h2>
    @if ($emails->isEmpty())
        <p class="text-sm text-ink-500">No emails sent yet.</p>
    @else
        @include('admin.emails._table')
    @endif

    <h2 class="mt-8 mb-3 text-lg font-semibold">Recent activity</h2>
    @if ($activity->isEmpty())
        <p class="text-sm text-ink-500">Nothing logged yet.</p>
    @else
        @include('admin.activity._list', ['entries' => $activity, 'showClient' => false])
    @endif
</x-admin-layout>
