<x-admin-layout title="Gateway log">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Gateway log</h1>
        <a href="{{ route('admin.payment-gateways.edit') }}" class="ml-auto text-sm text-brand-600">Payment gateways</a>
    </div>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">Every webhook, return and payment confirmation the payment gateways sent, and what came of it: accepted, rejected (a bad signature, a gateway that's off, or a payment the gateway turned down) or an error on this side. Secrets and card details are taken out before anything is kept. Entries are kept as long as the activity log (see Settings).</p>
    <form method="GET" class="mb-4 flex flex-wrap gap-2 text-sm">
        <label for="gateway-search" class="sr-only">Search</label>
        <input id="gateway-search" name="q" value="{{ $search }}" placeholder="Search event, message or payload" class="w-72 rounded-md px-3 py-1.5 ring-1 ring-ink-300">
        <label for="gateway-filter" class="sr-only">Gateway</label>
        <select id="gateway-filter" name="gateway" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
            <option value="">Any gateway</option>
            @foreach ($gateways as $value => $label)
                <option value="{{ $value }}" @selected($gateway === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <label for="gateway-outcome" class="sr-only">Outcome</label>
        <select id="gateway-outcome" name="outcome" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
            <option value="">Any outcome</option>
            @foreach (App\Models\GatewayLog::OUTCOMES as $value => $label)
                <option value="{{ $value }}" @selected($outcome === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Filter</button>
    </form>
    @if ($entries->isEmpty())
        <p class="text-sm text-ink-500">Nothing logged{{ $gateway || $outcome || $search !== '' ? ' that matches' : ' yet' }}.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Gateway</th><th class="px-4 py-2">Called</th><th class="px-4 py-2">Event</th><th class="px-4 py-2">Invoice</th><th class="px-4 py-2">Outcome</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($entries as $entry)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2"><a href="{{ route('admin.gateway-log.show', $entry) }}" class="text-brand-600">{{ $entry->created_at->format('M j, Y g:i:sa') }}</a></td>
                            <td class="px-4 py-2">{{ $entry->gatewayLabel() }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $entry->endpoint }}</td>
                            <td class="px-4 py-2">{{ $entry->event ?? '—' }}</td>
                            <td class="px-4 py-2">@if ($entry->invoice)<a href="{{ route('admin.invoices.show', $entry->invoice) }}" class="text-brand-600">#{{ $entry->invoice->number }}</a>@else — @endif</td>
                            <td class="px-4 py-2">@include('admin.gateway-log._outcome')@if ($entry->message) <span class="text-ink-500">{{ Illuminate\Support\Str::limit($entry->message, 80) }}</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $entries->links() }}</div>
    @endif
</x-admin-layout>
