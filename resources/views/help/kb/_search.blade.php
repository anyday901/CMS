<form method="GET" action="{{ route('kb.index') }}" class="mb-6 flex max-w-xl gap-2" role="search">
    <label for="kb-search" class="sr-only">{{ __('Search the knowledge base') }}</label>
    <input id="kb-search" type="search" name="q" value="{{ $search ?? '' }}" placeholder="{{ __('Search for answers…') }}" class="block w-full rounded-full bg-white px-4 py-2 text-sm ring-1 ring-ink-300">
    <button class="rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">{{ __('Search') }}</button>
</form>
