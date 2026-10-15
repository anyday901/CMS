@php use App\Support\GlobalSearch; @endphp
<x-admin-layout title="Search">
    <form method="GET" action="{{ route('admin.search') }}" role="search" class="mb-6 flex max-w-2xl gap-2">
        <label for="search-page" class="sr-only">Search</label>
        <input id="search-page" type="search" name="q" value="{{ $query }}" autofocus placeholder="Name, email, domain, invoice or ticket number" class="w-full rounded-full px-4 py-2 text-sm ring-1 ring-ink-300">
        <button class="rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">Search</button>
    </form>

    @if ($tooShort)
        <p class="text-sm text-ink-500">Type at least two letters, or a number.</p>
    @else
        @php
            $found = collect($results)->sum('total');
            $labels = ['clients' => 'Clients', 'services' => 'Services and domains', 'invoices' => 'Invoices', 'orders' => 'Orders', 'tickets' => 'Tickets'];
        @endphp
        <h1 class="mb-1 text-2xl font-semibold">{{ $found }} {{ Str::plural('result', $found) }} for “{{ $query }}”</h1>
        @if ($found > 0)
            <p class="mb-6 text-sm text-ink-500">
                @foreach (array_filter($results, fn ($section) => $section['total'] > 0) as $key => $section)
                    <a href="#{{ $key }}" class="text-brand-600">{{ $labels[$key] }} ({{ $section['total'] }})</a>@unless ($loop->last) · @endunless
                @endforeach
            </p>
        @else
            <p class="mb-6 text-sm text-ink-500">Nothing matched. Try part of a name, an email address, a domain, or an invoice, order or ticket number.</p>
        @endif

        @foreach ($results as $key => $section)
            @continue($section['total'] === 0)
            <section id="{{ $key }}" class="mb-10">
                <h2 class="mb-3 text-lg font-semibold">{{ $labels[$key] }} <span class="text-base font-normal text-ink-500">{{ $section['total'] }}</span></h2>
                @switch($key)
                    @case('clients')
                        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                            <table class="min-w-full text-sm">
                                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Name</th><th class="px-4 py-2">Company</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">Status</th></tr></thead>
                                <tbody class="divide-y divide-ink-100">
                                    @foreach ($section['items'] as $client)
                                        <tr>
                                            <td class="px-4 py-2 text-ink-500">{{ $client->id }}</td>
                                            <td class="px-4 py-2"><a href="{{ route('admin.clients.show', $client) }}" class="text-brand-600">{{ $client->fullName() }}</a> <x-client-group-badge :group="$client->group" class="ml-1" /></td>
                                            <td class="px-4 py-2">{{ $client->company }}</td>
                                            <td class="px-4 py-2">{{ $client->email }}</td>
                                            <td class="px-4 py-2"><x-status-badge :status="$client->status" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @break
                    @case('services')
                        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                            <table class="min-w-full text-sm">
                                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Service</th><th class="px-4 py-2">Client</th><th class="px-4 py-2">Next due</th><th class="px-4 py-2">Status</th></tr></thead>
                                <tbody class="divide-y divide-ink-100">
                                    @foreach ($section['items'] as $service)
                                        <tr>
                                            <td class="px-4 py-2 text-ink-500">{{ $service->id }}</td>
                                            <td class="px-4 py-2"><a href="{{ route('admin.services.show', $service) }}" class="text-brand-600">{{ $service->description() }}</a>@if ($service->username)<span class="block text-xs text-ink-500">Login {{ $service->username }}</span>@endif</td>
                                            <td class="px-4 py-2"><a href="{{ route('admin.clients.show', $service->client) }}" class="text-brand-600">{{ $service->client->fullName() }}</a></td>
                                            <td class="px-4 py-2">{{ $service->next_due_date?->format('M j, Y') ?? '—' }}</td>
                                            <td class="px-4 py-2"><x-status-badge :status="$service->status" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @break
                    @case('invoices')
                        @include('admin.invoices._table', ['invoices' => $section['items']])
                        @break
                    @case('orders')
                        @include('admin.orders._table', ['orders' => $section['items']])
                        @break
                    @case('tickets')
                        @include('admin.tickets._table', ['tickets' => $section['items']])
                        @break
                @endswitch
                @if ($section['total'] > GlobalSearch::LIMIT)
                    <p class="mt-2 text-sm text-ink-500">Showing the first {{ GlobalSearch::LIMIT }}. Add more words to narrow it down.@if ($key === 'clients') Or use the <a href="{{ route('admin.clients.index', ['q' => $query, 'show_closed' => 1]) }}" class="text-brand-600">clients search</a>.@endif</p>
                @endif
            </section>
        @endforeach
    @endif
</x-admin-layout>
