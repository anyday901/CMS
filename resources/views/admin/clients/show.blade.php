@php use App\Support\Money; @endphp
<x-admin-layout :title="$client->fullName()">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $client->fullName() }}</h1>
        <x-status-badge :status="$client->status" />
        <form method="POST" action="{{ route('admin.clients.password-link', $client) }}" class="ml-auto">@csrf
            <button class="rounded-md px-3 py-1.5 text-sm ring-1 ring-gray-300 hover:bg-gray-100">Email portal password link</button>
        </form>
        <a href="{{ route('admin.clients.edit', $client) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-gray-300 hover:bg-gray-100">Edit</a>
        <a href="{{ route('admin.services.create', $client) }}" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Add service</a>
    </div>

    <div class="mb-8 grid gap-4 rounded-lg border border-gray-200 bg-white p-4 text-sm md:grid-cols-3">
        <div><div class="text-gray-500">Email</div>{{ $client->email }}</div>
        <div><div class="text-gray-500">Company</div>{{ $client->company ?: '—' }}</div>
        <div><div class="text-gray-500">Phone</div>{{ $client->phone ?: '—' }}</div>
        <div><div class="text-gray-500">Address</div>{{ collect([$client->address1, $client->address2, $client->city, $client->state, $client->postcode, $client->country])->filter()->join(', ') ?: '—' }}</div>
        <div><div class="text-gray-500">Credit balance</div>{{ Money::format($client->credit_balance, $client->currency) }}</div>
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
</x-admin-layout>
