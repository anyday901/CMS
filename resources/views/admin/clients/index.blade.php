<x-admin-layout title="Clients">
    @php
        $input = 'mt-1 w-full rounded-md px-3 py-1.5 ring-1 ring-ink-300';
        $panelInput = $input.' bg-white';
        $yesNo = ['' => 'Any', 'yes' => 'Yes', 'no' => 'No'];
    @endphp
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Clients</h1>
        @can('manage-clients')
            <a href="{{ route('admin.clients.create') }}" class="ml-auto rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">Add client</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('admin.clients.index') }}" class="mb-4 overflow-hidden rounded-lg border border-ink-200 bg-white text-sm" role="search" aria-label="Search clients">
        @if ($search->showsClosed())
            <input type="hidden" name="show_closed" value="1">
        @endif
        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[repeat(4,minmax(0,1fr))_auto]">
            <label class="block">Client or company name
                <input type="search" name="q" value="{{ $search->get('q') }}" class="{{ $input }}">
            </label>
            <label class="block">Email address
                <input type="search" name="email" value="{{ $search->get('email') }}" class="{{ $input }}">
            </label>
            <label class="block">Phone number
                <input type="search" name="phone" value="{{ $search->get('phone') }}" class="{{ $input }}">
            </label>
            <label class="block">Status
                <select name="status" class="{{ $input }}">
                    <option value="">Any</option>
                    @foreach (App\Enums\ClientStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected($search->get('status') === $status->value)>{{ ucfirst($status->value) }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end gap-2">
                <button type="button" aria-controls="client-search-advanced" aria-expanded="{{ $search->usesAdvanced() ? 'true' : 'false' }}"
                        onclick="const panel = document.getElementById('client-search-advanced'); panel.hidden = ! panel.hidden; this.setAttribute('aria-expanded', String(! panel.hidden));"
                        class="rounded-full px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Advanced</button>
                <button class="rounded-full bg-brand-600 px-4 py-1.5 font-medium text-white hover:bg-brand-500">Search</button>
            </div>
        </div>
        <div id="client-search-advanced" class="grid gap-x-6 gap-y-3 border-t border-ink-200 bg-ink-50 p-4 sm:grid-cols-2 lg:grid-cols-3" @if (! $search->usesAdvanced()) hidden @endif>
            @foreach (['address1' => 'Address 1', 'address2' => 'Address 2', 'city' => 'City', 'state' => 'State or region', 'postcode' => 'Postcode'] as $field => $label)
                <label class="block">{{ $label }}
                    <input name="{{ $field }}" value="{{ $search->get($field) }}" class="{{ $panelInput }}">
                </label>
            @endforeach
            @foreach (['country' => ['Country', $countries], 'currency' => ['Currency', $currencies]] as $field => [$label, $options])
                <label class="block">{{ $label }}
                    <select name="{{ $field }}" class="{{ $panelInput }}">
                        <option value="">Any</option>
                        @foreach ($options as $option)
                            <option value="{{ $option }}" @selected($search->get($field) === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
            <label class="block">Client group
                <select name="group" class="{{ $panelInput }}">
                    <option value="">Any</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}" @selected($search->get('group') === (string) $group->id)>{{ $group->name }}</option>
                    @endforeach
                    <option value="{{ App\Support\ClientSearch::NO_GROUP }}" @selected($search->get('group') === App\Support\ClientSearch::NO_GROUP)>Not in a group</option>
                </select>
            </label>
            @foreach (['credit_balance' => 'Has credit balance', 'tax_exempt' => 'Tax exempt', 'marketing_emails' => 'Agreed to marketing emails'] as $field => $label)
                <label class="block">{{ $label }}
                    <select name="{{ $field }}" class="{{ $panelInput }}">
                        @foreach ($yesNo as $value => $text)
                            <option value="{{ $value }}" @selected($search->get($field) === $value)>{{ $text }}</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
            <fieldset class="block">
                <legend>Signup date</legend>
                <div class="flex items-center gap-2">
                    <input type="date" name="signup_from" value="{{ $search->get('signup_from') }}" aria-label="Signed up on or after" class="{{ $panelInput }}">
                    <span class="mt-1 text-ink-500">to</span>
                    <input type="date" name="signup_to" value="{{ $search->get('signup_to') }}" aria-label="Signed up on or before" class="{{ $panelInput }}">
                </div>
            </fieldset>
        </div>
    </form>

    <div class="mb-3 flex flex-wrap items-center gap-3 text-sm text-ink-500">
        <p>{{ $clients->total() }} {{ Str::plural('client', $clients->total()) }} found</p>
        @if ($search->filters() !== [])
            <a href="{{ route('admin.clients.index', $search->showsClosed() ? ['show_closed' => 1] : []) }}" class="text-brand-600">Clear search</a>
        @endif
        @can('manage-clients')
            @if ($clients->total() > 0)
                <a href="{{ route('admin.clients.export', request()->query()) }}" class="text-brand-600">Export to CSV</a>
            @endif
        @endcan
        @unless ($search->get('status'))
            @php $hiding = $search->hidesClosed(); @endphp
            <a href="{{ route('admin.clients.index', $search->withClosed($hiding)) }}" role="switch" aria-checked="{{ $hiding ? 'true' : 'false' }}"
               class="ml-auto inline-flex items-center gap-2 rounded-full px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">
                <span class="rounded-full px-1.5 text-xs font-semibold {{ $hiding ? 'bg-brand-600 text-white' : 'bg-ink-200 text-ink-700' }}">{{ $hiding ? 'On' : 'Off' }}</span>
                {{ $hiding ? "Hide closed clients ({$closedCount})" : 'Hide closed clients' }}
            </a>
        @endunless
    </div>

    @if ($clients->isEmpty())
        <p class="text-sm text-ink-500">No clients found.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Company</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">Services</th><th class="px-4 py-2">Signed up</th><th class="px-4 py-2">Status</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($clients as $client)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.clients.show', $client) }}" class="text-brand-600">{{ $client->fullName() }}</a> <x-client-group-badge :group="$client->group" class="ml-1" /></td>
                            <td class="px-4 py-2">{{ $client->company }}</td>
                            <td class="px-4 py-2">{{ $client->email }}</td>
                            <td class="px-4 py-2">{{ $client->services_count }}</td>
                            <td class="px-4 py-2">{{ $client->created_at?->toDateString() }}</td>
                            <td class="px-4 py-2"><x-status-badge :status="$client->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $clients->links() }}</div>
    @endif
</x-admin-layout>
