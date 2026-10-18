@php use App\Support\Money; @endphp
<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500">
            <tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Subject</th>@unless ($hideClient ?? false)<th class="px-4 py-2">Client</th>@endunless<th class="px-4 py-2">Issued</th><th class="px-4 py-2">Valid until</th><th class="px-4 py-2 text-right">Total</th><th class="px-4 py-2">Stage</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($quotes as $quote)
                <tr>
                    <td class="px-4 py-2"><a href="{{ route('admin.quotes.show', $quote) }}" class="text-brand-600">{{ $quote->number }}</a></td>
                    <td class="px-4 py-2">{{ $quote->subject }}</td>
                    @unless ($hideClient ?? false)<td class="px-4 py-2"><a href="{{ route('admin.clients.show', $quote->client) }}" class="text-brand-600">{{ $quote->client->fullName() }}</a></td>@endunless
                    <td class="px-4 py-2">{{ $quote->issue_date->format('M j, Y') }}</td>
                    <td class="px-4 py-2 {{ $quote->awaitsClient() && $quote->isExpired() ? 'text-red-700' : '' }}">{{ $quote->valid_until->format('M j, Y') }}</td>
                    <td class="px-4 py-2 text-right">{{ Money::format($quote->total, $quote->currency) }}</td>
                    <td class="px-4 py-2"><x-status-badge :status="$quote->stage">{{ $quote->stage->label() }}</x-status-badge></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
