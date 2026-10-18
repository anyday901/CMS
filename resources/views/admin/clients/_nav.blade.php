{{-- The client page's section menu, also beside pages shown inside it. Classes here match $navOn and $navOff in clients/show. --}}
<nav aria-label="Client sections" class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3 md:w-52 md:shrink-0 md:grid-cols-1">
    @foreach ($sections as $group => $items)
        <div>
            <div class="px-3 pb-1 text-xs font-semibold tracking-wide text-ink-500 uppercase">{{ $group }}</div>
            @foreach ($items as $key => $label)
                <a href="{{ route('admin.clients.show', [$client, 'tab' => $key]) }}" data-section-link="{{ $key }}" @if ($section === $key) aria-current="page" @endif
                   class="flex items-center gap-2 rounded-md px-3 py-1.5 {{ $section === $key ? 'bg-brand-100 font-semibold text-brand-700' : 'text-ink-700 hover:bg-ink-100' }}">
                    <span class="grow">{{ $label }}</span>
                    @isset($counts[$key])<span class="text-xs text-ink-500">{{ $counts[$key] }}</span>@endisset
                </a>
            @endforeach
        </div>
    @endforeach
</nav>
