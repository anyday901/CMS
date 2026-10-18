<x-admin-layout :title="'Edit '.$currency->code">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $currency->code }}</h1>
        <a href="{{ route('admin.currencies.index') }}" class="ml-auto text-sm text-brand-600">Back to currencies</a>
    </div>
    <form method="POST" action="{{ route('admin.currencies.update', $currency) }}" class="max-w-md space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf @method('PUT')
        <label class="block">Exchange rate
            <input name="rate" value="{{ old('rate', $currency->rate) }}" required inputmode="decimal" class="mt-1 w-full rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">
            <span class="text-ink-500">How many {{ $currency->code }} make one {{ config('billing.currency') }}. Changing it doesn't change any price until you use Update product prices.</span>
        </label>
        <p class="text-ink-500">Last updated {{ $currency->rate_updated_at?->format('M j, Y g:i a') ?? 'never' }}.</p>
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
