@php use App\Support\Money; @endphp
<x-admin-layout :title="$client->fullName()">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $client->fullName() }}</h1>
        <x-status-badge :status="$client->status" />
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @can('manage-clients')
                <form method="POST" action="{{ route('admin.clients.password-link', $client) }}">@csrf
                    <button class="rounded-md px-3 py-1.5 text-sm ring-1 ring-gray-300 hover:bg-gray-100">Email portal password link</button>
                </form>
                <a href="{{ route('admin.clients.edit', $client) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-gray-300 hover:bg-gray-100">Edit</a>
            @endcan
            @can('manage-billing')
                <a href="{{ route('admin.invoices.create', $client) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-gray-300 hover:bg-gray-100">New invoice</a>
            @endcan
            @can('manage-clients')
                <a href="{{ route('admin.services.create', $client) }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Add service</a>
            @endcan
        </div>
    </div>

    <div class="mb-8 grid gap-4 rounded-lg border border-gray-200 bg-white p-4 text-sm md:grid-cols-3">
        <div><div class="text-gray-500">Email</div>{{ $client->email }}</div>
        <div><div class="text-gray-500">Company</div>{{ $client->company ?: '—' }}</div>
        <div><div class="text-gray-500">Phone</div>{{ $client->phone ?: '—' }}</div>
        <div><div class="text-gray-500">Address</div>{{ collect([$client->address1, $client->address2, $client->city, $client->state, $client->postcode, $client->country])->filter()->join(', ') ?: '—' }}</div>
        <div><div class="text-gray-500">Credit balance</div>{{ Money::format($client->credit_balance, $client->currency) }}</div>
        <div><div class="text-gray-500">Tax</div>{{ $client->tax_exempt ? 'Exempt' : (App\Models\TaxRule::forClient($client)?->name ?? 'None') }}</div>
        <div><div class="text-gray-500">Client since</div>{{ $client->created_at->format('M j, Y') }}</div>
        @if ($client->notes)<div class="md:col-span-3"><div class="text-gray-500">Notes</div>{!! nl2br(e($client->notes)) !!}</div>@endif
    </div>

    <h2 class="mb-3 text-lg font-semibold">Services</h2>
    @if ($client->services->isEmpty())
        <p class="mb-8 text-sm text-gray-500">No services yet.</p>
    @else
        <div class="mb-8 overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Service</th><th class="px-4 py-2">Cycle</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2">Next due</th><th class="px-4 py-2">Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($client->services as $service)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.services.show', $service) }}" class="text-indigo-600">{{ $service->description() }}</a></td>
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

    <h2 class="mb-3 text-lg font-semibold">Invoices</h2>
    @if ($client->invoices->isEmpty())
        <p class="text-sm text-gray-500">No invoices yet.</p>
    @else
        @include('admin.invoices._table', ['invoices' => $client->invoices->each->setRelation('client', $client)])
    @endif

    <h2 class="mt-8 mb-3 text-lg font-semibold">Credit <span class="text-base font-normal text-gray-500">{{ Money::format($client->credit_balance, $client->currency) }} available</span></h2>
    @can('manage-billing')
        <details class="mb-4 text-sm" @if ($errors->hasAny(['direction', 'amount', 'reason'])) open @endif>
            <summary class="cursor-pointer text-indigo-600">Adjust credit</summary>
            <form method="POST" action="{{ route('admin.clients.credit', $client) }}" class="mt-2 flex flex-wrap items-end gap-2">
                @csrf
                <label class="block">Change
                    <select name="direction" class="mt-1 block rounded-md px-2 py-1 ring-1 ring-gray-300">
                        <option value="add" @selected(old('direction') !== 'remove')>Add credit</option>
                        <option value="remove" @selected(old('direction') === 'remove')>Remove credit</option>
                    </select>
                </label>
                <label class="block">Amount
                    <input name="amount" value="{{ old('amount') }}" required placeholder="0.00" class="mt-1 block w-28 rounded-md px-2 py-1 ring-1 ring-gray-300">
                </label>
                <label class="block">Reason
                    <input name="reason" value="{{ old('reason') }}" required maxlength="255" placeholder="Goodwill credit for outage" class="mt-1 block w-72 rounded-md px-2 py-1 ring-1 ring-gray-300">
                </label>
                <span class="basis-full text-gray-500">The client sees the reason in their credit history.</span>
                <button class="rounded-md px-3 py-1 ring-1 ring-gray-300 hover:bg-gray-100">Save</button>
            </form>
        </details>
    @endcan
    @if ($credit->isEmpty())
        <p class="text-sm text-gray-500">No credit history yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">What</th><th class="px-4 py-2">By</th><th class="px-4 py-2 text-right">Change</th><th class="px-4 py-2 text-right">Balance</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($credit as $entry)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2">{{ $entry->created_at->format('M j, Y') }}</td>
                            <td class="px-4 py-2">@if ($entry->invoice)<a href="{{ route('admin.invoices.show', $entry->invoice) }}" class="text-indigo-600">{{ $entry->description }}</a>@else{{ $entry->description }}@endif</td>
                            <td class="px-4 py-2 text-gray-500">{{ $entry->user?->name ?? 'System' }}</td>
                            <td class="px-4 py-2 text-right {{ $entry->amount < 0 ? 'text-red-700' : 'text-green-700' }}">{{ $entry->amount > 0 ? '+' : '' }}{{ Money::format($entry->amount, $client->currency) }}</td>
                            <td class="px-4 py-2 text-right">{{ Money::format($entry->balance_after, $client->currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="mt-8 mb-3 text-lg font-semibold">Recent activity</h2>
    @if ($activity->isEmpty())
        <p class="text-sm text-gray-500">Nothing logged yet.</p>
    @else
        @include('admin.activity._list', ['entries' => $activity, 'showClient' => false])
    @endif
</x-admin-layout>
