<x-admin-layout :title="$webhook->name">
    <p class="mb-2 text-sm"><a href="{{ route('admin.webhooks.index') }}" class="text-brand-600">Webhooks</a></p>
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $webhook->name }}</h1>
        <form method="POST" action="{{ route('admin.webhooks.test', $webhook) }}" class="ml-auto">
            @csrf
            <button class="rounded-full px-4 py-1.5 text-sm font-medium text-ink-700 ring-1 ring-ink-300 hover:bg-ink-50">Send a test</button>
        </form>
        <form method="POST" action="{{ route('admin.webhooks.destroy', $webhook) }}" onsubmit="return confirm('Delete this webhook and its delivery log?')">
            @csrf @method('DELETE')
            <button class="rounded-full px-4 py-1.5 text-sm font-medium text-red-700 ring-1 ring-red-300 hover:bg-red-50">Delete</button>
        </form>
    </div>

    <div class="grid gap-6 md:grid-cols-3">
        <form method="POST" action="{{ route('admin.webhooks.update', $webhook) }}" class="space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm md:col-span-2">
            @csrf @method('PUT')
            @include('admin.webhooks.fields')
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
        </form>

        <div class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            <h2 class="font-semibold">Signing secret</h2>
            <p class="text-ink-600">Each delivery's <code class="rounded bg-ink-100 px-1">X-Webhook-Signature</code> is <code class="rounded bg-ink-100 px-1">sha256=</code> and the HMAC-SHA256 of the raw body with this secret. Check it before trusting a delivery.</p>
            <details>
                <summary class="cursor-pointer text-brand-600">Show the secret</summary>
                <code class="mt-2 block break-all rounded bg-ink-100 p-2">{{ $webhook->secret }}</code>
            </details>
            <form method="POST" action="{{ route('admin.webhooks.secret', $webhook) }}" onsubmit="return confirm('Make a new secret? The old one stops working at once.')">
                @csrf
                <button class="text-brand-600 hover:underline">Make a new secret</button>
            </form>
        </div>
    </div>

    <h2 class="mb-2 mt-8 text-lg font-semibold">Recent deliveries</h2>
    @if ($deliveries->isEmpty())
        <p class="text-sm text-ink-500">Nothing sent yet.</p>
    @else
        <div class="space-y-2">
            @foreach ($deliveries as $delivery)
                <details class="rounded-lg border border-ink-200 bg-white text-sm">
                    <summary class="flex cursor-pointer flex-wrap items-center gap-3 px-4 py-2">
                        <span @class(['rounded-full px-2 py-0.5 text-xs font-medium', 'bg-green-100 text-green-800' => $delivery->status === 'delivered', 'bg-red-100 text-red-800' => $delivery->status === 'failed', 'bg-ink-100 text-ink-600' => $delivery->status === 'pending'])>{{ ucfirst($delivery->status) }}</span>
                        <code>{{ $delivery->event }}</code>
                        <span class="text-ink-500">{{ $delivery->created_at->format('M j, Y g:i a') }}</span>
                        <span class="ml-auto text-ink-500">{{ $delivery->response_status ? 'HTTP '.$delivery->response_status : '' }} · {{ $delivery->attempts }} {{ Str::plural('try', $delivery->attempts) }}</span>
                    </summary>
                    <div class="space-y-2 border-t border-ink-100 px-4 py-3">
                        @if ($delivery->error)<p class="text-red-700">{{ $delivery->error }}</p>@endif
                        <p class="font-medium">Sent</p>
                        <pre class="max-h-64 overflow-auto rounded bg-ink-50 p-2 text-xs">{{ json_encode($delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @if (filled($delivery->response_body))
                            <p class="font-medium">Answer</p>
                            <pre class="max-h-40 overflow-auto rounded bg-ink-50 p-2 text-xs">{{ $delivery->response_body }}</pre>
                        @endif
                        <form method="POST" action="{{ route('admin.webhook-deliveries.redeliver', $delivery) }}">
                            @csrf
                            <button class="text-brand-600 hover:underline">Send again</button>
                        </form>
                    </div>
                </details>
            @endforeach
        </div>
    @endif
</x-admin-layout>
