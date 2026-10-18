@php use App\Enums\QuoteStage; @endphp
<x-admin-layout title="Quotes">
    <h1 class="mb-1 text-2xl font-semibold">Quotes</h1>
    <p class="mb-4 text-sm text-ink-600">Proposals for clients. Send one and the client can accept it in the portal, which turns it into an invoice. To make a quote, open the client and press <span class="font-medium">New quote</span>.</p>
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        @foreach (['' => 'All'] + collect(QuoteStage::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all() as $value => $label)
            <a href="{{ route('admin.quotes.index', array_filter(['stage' => $value, 'q' => $search])) }}"
               class="rounded-full px-3 py-1 {{ ($stage ?? '') === $value ? 'bg-brand-600 text-white' : 'bg-white ring-1 ring-ink-300' }}">{{ $label }}</a>
        @endforeach
        <form method="GET" action="{{ route('admin.quotes.index') }}" role="search" class="ml-auto flex gap-2">
            @if ($stage)<input type="hidden" name="stage" value="{{ $stage }}">@endif
            <label for="quote-search" class="sr-only">Search quotes</label>
            <input id="quote-search" type="search" name="q" value="{{ $search }}" placeholder="Number, subject or client" class="w-56 rounded-full px-3 py-1 ring-1 ring-ink-300">
            <button class="rounded-full px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Search</button>
        </form>
    </div>
    @if ($quotes->isEmpty())
        <p class="text-sm text-ink-500">No quotes.</p>
    @else
        @include('admin.quotes._table')
        <div class="mt-4">{{ $quotes->links() }}</div>
    @endif
</x-admin-layout>
