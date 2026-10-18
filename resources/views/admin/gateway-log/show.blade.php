<x-admin-layout :title="'Gateway log #'.$entry->id">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $entry->gatewayLabel() }} {{ $entry->event ?? $entry->endpoint }}</h1>
        @include('admin.gateway-log._outcome')
        <a href="{{ route('admin.gateway-log.index') }}" class="ml-auto text-sm text-brand-600">Gateway log</a>
    </div>
    <div class="mb-6 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm md:grid-cols-3">
        <div><div class="text-ink-500">Received</div>{{ $entry->created_at->format('M j, Y g:i:sa') }}</div>
        <div><div class="text-ink-500">Called</div><span class="font-mono text-xs">{{ $entry->endpoint }}</span> · answered {{ $entry->response_status }}</div>
        <div><div class="text-ink-500">From</div>{{ $entry->ip_address ?? '—' }}</div>
        <div><div class="text-ink-500">Client</div>@if ($entry->client)<a href="{{ route('admin.clients.show', $entry->client) }}" class="text-brand-600">{{ $entry->client->fullName() }}</a>@else — @endif</div>
        <div><div class="text-ink-500">Invoice</div>@if ($entry->invoice)<a href="{{ route('admin.invoices.show', $entry->invoice) }}" class="text-brand-600">#{{ $entry->invoice->number }}</a>@else — @endif</div>
        <div><div class="text-ink-500">Event</div>{{ $entry->event ?? '—' }}</div>
    </div>
    @if ($entry->message)<p class="mb-6 rounded-md {{ $entry->outcome === App\Models\GatewayLog::ERROR ? 'bg-red-50 text-red-800' : 'bg-orange-50 text-orange-800' }} px-4 py-3 text-sm">{{ $entry->message }}</p>@endif
    <h2 class="mb-2 font-display text-base font-bold">What the gateway sent</h2>
    @if ($entry->payload)
        <pre class="overflow-x-auto rounded-lg border border-ink-200 bg-white p-4 text-xs">{{ $entry->prettyPayload() }}</pre>
        <p class="mt-2 text-xs text-ink-500">Secrets and card details show as {{ App\Payments\GatewayLogger::REDACTED }}.</p>
    @else
        <p class="text-sm text-ink-500">Nothing was sent with this call.</p>
    @endif
</x-admin-layout>
