@php use App\Support\Money; @endphp
<x-admin-layout :title="'Quote '.$quote->number">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Quote #{{ $quote->number }}</h1>
        <x-status-badge :status="$quote->stage">{{ $quote->stage->label() }}</x-status-badge>
        @if ($quote->awaitsClient() && $quote->isExpired())<x-status-badge status="expired" />@endif
        <div class="ml-auto flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.quotes.pdf', $quote) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Download PDF</a>
            @can('manage-billing')
                @if ($quote->isEditable())
                    <a href="{{ route('admin.quotes.edit', $quote) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Edit</a>
                @endif
                <form method="POST" action="{{ route('admin.quotes.duplicate', $quote) }}">
                    @csrf
                    <button class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Duplicate</button>
                </form>
                <form method="POST" action="{{ route('admin.quotes.destroy', $quote) }}" onsubmit="return confirm('Delete this quote? {{ $quote->invoice ? 'Its invoice is kept.' : 'This cannot be undone.' }}')">
                    @csrf @method('DELETE')
                    <button class="rounded-md px-3 py-1.5 text-sm text-red-700 ring-1 ring-ink-300 hover:bg-red-50">Delete</button>
                </form>
                @if ($quote->isEditable())
                    <form method="POST" action="{{ route('admin.quotes.convert', $quote) }}" onsubmit="return confirm('Make an unpaid invoice from this quote and mark it accepted? The client is emailed the invoice.')">
                        @csrf
                        <button class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Convert to invoice</button>
                    </form>
                    <form method="POST" action="{{ route('admin.quotes.send', $quote) }}" onsubmit="return confirm('Email this quote to the client with the PDF attached? It will show in their portal.')">
                        @csrf
                        <button class="rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">{{ $quote->delivered_at ? 'Send again' : 'Send to client' }}</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>
    @if ($quote->invoice)
        <p class="mb-6 rounded-md bg-brand-50 px-4 py-3 text-sm text-brand-800">Accepted{{ $quote->accepted_at ? ' on '.$quote->accepted_at->format('M j, Y') : '' }} and converted to <a href="{{ route('admin.invoices.show', $quote->invoice) }}" class="font-medium underline">invoice #{{ $quote->invoice->number }}</a> ({{ $quote->invoice->status->value }}).</p>
    @elseif ($quote->stage->value === 'draft')
        <p class="mb-6 rounded-md bg-yellow-50 px-4 py-3 text-sm text-yellow-800">This is a draft. The client can't see it until you send it, or set the stage to Delivered.</p>
    @endif

    <div class="grid gap-6 md:grid-cols-3">
        <div class="space-y-6 md:col-span-2">
            <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <div class="grid grid-cols-2 gap-2">
                    <div class="col-span-2"><span class="text-ink-500">Subject:</span> {{ $quote->subject }}</div>
                    <div><span class="text-ink-500">Client:</span> <a href="{{ route('admin.clients.show', $quote->client) }}" class="text-brand-600">{{ $quote->client->fullName() }}</a></div>
                    @if ($quote->client_tax_id)<div><span class="text-ink-500">Client tax ID:</span> {{ $quote->client_tax_id }}</div>@endif
                    <div><span class="text-ink-500">Issued:</span> {{ $quote->issue_date->format('M j, Y') }}</div>
                    <div><span class="text-ink-500">Valid until:</span> <span class="{{ $quote->awaitsClient() && $quote->isExpired() ? 'text-red-700' : '' }}">{{ $quote->valid_until->format('M j, Y') }}</span></div>
                    @if ($quote->delivered_at)<div><span class="text-ink-500">Delivered:</span> {{ $quote->delivered_at->format('M j, Y') }}</div>@endif
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Description</th><th class="px-4 py-2 text-right">Amount</th></tr></thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($quote->items as $item)
                            <tr>
                                <td class="px-4 py-2">{{ $item->description }}@unless ($item->taxable)<span class="ml-1 text-xs text-ink-500">(no tax)</span>@endunless</td>
                                <td class="px-4 py-2 text-right">{{ Money::format($item->amount, $quote->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="font-medium">
                        @if ($quote->tax && ! $quote->tax_inclusive)<tr><td class="px-4 py-2 text-right">Subtotal</td><td class="px-4 py-2 text-right">{{ Money::format($quote->subtotal, $quote->currency) }}</td></tr>@endif
                        @if ($quote->tax && ! $quote->tax_inclusive)@foreach ($quote->taxLines() as [$taxLabel, $taxAmount])<tr><td class="px-4 py-2 text-right">{{ $taxLabel }}</td><td class="px-4 py-2 text-right">{{ Money::format($taxAmount, $quote->currency) }}</td></tr>@endforeach @endif
                        <tr><td class="px-4 py-2 text-right">Total</td><td class="px-4 py-2 text-right">{{ Money::format($quote->total, $quote->currency) }}</td></tr>
                        @if ($quote->tax && $quote->tax_inclusive)@foreach ($quote->taxLines() as [$taxLabel, $taxAmount])<tr><td class="px-4 py-2 text-right font-normal text-ink-500">Includes {{ $taxLabel }}</td><td class="px-4 py-2 text-right font-normal text-ink-500">{{ Money::format($taxAmount, $quote->currency) }}</td></tr>@endforeach @endif
                    </tfoot>
                </table>
            </div>

            <x-reverse-charge :document="$quote" />

            @if ($quote->notes)
                <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
                    <h2 class="mb-2 font-semibold">Proposal</h2>
                    <div class="text-ink-700">{!! nl2br(e($quote->notes)) !!}</div>
                </div>
            @endif
        </div>

        <div class="h-fit space-y-6">
            @if ($quote->admin_notes)
                <div class="rounded-md border border-amber-200 bg-sun-300/30 p-4 text-sm text-amber-950">
                    <h2 class="mb-2 font-semibold">Admin notes</h2>
                    <div>{!! nl2br(e($quote->admin_notes)) !!}</div>
                </div>
            @endif
            <div class="rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <h2 class="mb-2 font-semibold">History</h2>
                @forelse ($activity as $entry)
                    <div class="border-b border-ink-100 py-1.5 last:border-0">{{ $entry->description }}<span class="block text-xs text-ink-500">{{ $entry->actor_name }}, {{ $entry->created_at->format('M j, Y g:i a') }}</span></div>
                @empty
                    <p class="text-ink-500">Nothing yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-admin-layout>
