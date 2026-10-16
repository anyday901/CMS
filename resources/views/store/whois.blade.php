<x-portal-layout :title="__('WHOIS lookup')" :store="true">
    <p class="mb-2 text-sm"><a href="{{ route('domains.index') }}" class="text-brand-600">{{ __('Domains') }}</a></p>
    <h1 class="mb-2 text-2xl font-semibold">{{ __('WHOIS lookup') }}</h1>
    <p class="mb-6 text-sm text-ink-600">{{ __("See who a domain is registered with and when it expires, from the registry's public record.") }}</p>

    <form method="GET" action="{{ route('domains.whois') }}" role="search" class="mb-8 flex max-w-2xl gap-2 text-sm">
        <label for="whois-search" class="sr-only">{{ __('Domain name') }}</label>
        <input id="whois-search" name="domain" value="{{ $search }}" required maxlength="253" placeholder="example.com" class="min-w-0 grow rounded-full bg-white px-4 py-2.5 ring-1 ring-ink-300">
        <button class="rounded-full bg-brand-600 px-5 py-2.5 font-medium text-white hover:bg-brand-500">{{ __('Look up') }}</button>
    </form>

    @if ($invalid)
        <p class="max-w-2xl rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">{{ __("That isn't a domain name. Use letters, numbers and hyphens, such as example.com.") }}</p>
    @elseif ($result)
        <div class="max-w-2xl rounded-lg border border-ink-200 bg-white p-5 text-sm">
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <h2 class="text-lg font-semibold">{{ $result->name }}</h2>
                @if ($result->available === true)
                    <x-status-badge status="active">{{ __('Available') }}</x-status-badge>
                    <a href="{{ route('domains.index', ['domain' => $result->name]) }}" class="ml-auto rounded-full bg-brand-600 px-4 py-1.5 font-medium text-white hover:bg-brand-500">{{ __('Register it') }}</a>
                @elseif ($result->available === false)
                    <x-status-badge status="closed">{{ __('Registered') }}</x-status-badge>
                @endif
            </div>
            @if ($result->error)
                <p class="text-orange-800">{{ __("We couldn't look this domain up right now. Please try again later.") }}</p>
            @endif
            @if ($result->fields)
                <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-[10rem_1fr]">
                    @foreach ($result->fields as $label => $value)
                        <dt class="text-ink-500">{{ __($label) }}</dt><dd class="break-words">{{ $value }}</dd>
                    @endforeach
                </dl>
            @endif
            @if ($result->text)
                <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap rounded-md bg-ink-50 p-3 text-xs">{{ $result->text }}</pre>
            @endif
            @if ($result->available === true && ! $result->fields)
                <p class="text-ink-600">{{ __('Nobody has registered this domain.') }}</p>
            @endif
        </div>
    @endif
</x-portal-layout>
