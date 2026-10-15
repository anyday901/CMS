<x-portal-layout :title="__('Knowledge base')" :store="true">
    @include('help._nav')
    <h1 class="mb-2 text-2xl font-semibold">{{ __('Knowledge base') }}</h1>
    <p class="mb-4 text-sm text-ink-600">{!! __("Answers to common questions. Can't find what you need? :link.", ['link' => '<a href="'.e(route('tickets.guest.create')).'" class="text-brand-600">'.e(__('Open a ticket')).'</a>']) !!}</p>
    @include('help.kb._search')

    @if ($results !== null)
        <h2 class="mb-3 text-lg font-semibold">{{ trans_choice(':count result for “:search”|:count results for “:search”', $results->total(), ['search' => $search]) }}</h2>
        @if ($results->isEmpty())
            <p class="mb-8 text-sm text-ink-500">{{ __('Nothing matched. Try fewer or different words.') }}</p>
        @else
            <div class="mb-8 divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
                @foreach ($results as $article)
                    <div class="px-4 py-3">
                        <a href="{{ route('kb.article', $article) }}" class="font-medium text-brand-600">{{ $article->title }}</a>
                        <span class="text-ink-500">{{ __('in :category', ['category' => $article->category->name]) }}</span>
                        <p class="text-ink-600">{{ App\Support\Markdown::excerpt($article->body, 180) }}</p>
                    </div>
                @endforeach
            </div>
            {{ $results->links() }}
        @endif
    @endif

    @if ($categories->isEmpty())
        <p class="text-sm text-ink-500">{{ __('There are no articles yet.') }}</p>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($categories as $category)
                <a href="{{ route('kb.category', $category) }}" class="block rounded-lg border border-ink-200 bg-white p-4 text-sm hover:ring-1 hover:ring-brand-300">
                    <span class="block font-display text-base font-semibold">{{ $category->name }} <span class="text-sm font-normal text-ink-500">({{ $category->articles_count }})</span></span>
                    @if ($category->description)<span class="mt-1 block text-ink-600">{{ $category->description }}</span>@endif
                    @if ($category->children->isNotEmpty())<span class="mt-2 block text-ink-500">{{ $category->children->pluck('name')->join(' · ') }}</span>@endif
                </a>
            @endforeach
        </div>
    @endif

    @if ($popular->isNotEmpty() && $results === null)
        <h2 class="mt-8 mb-3 text-lg font-semibold">{{ __('Popular articles') }}</h2>
        <ul class="space-y-1 text-sm">
            @foreach ($popular as $article)
                <li><a href="{{ route('kb.article', $article) }}" class="text-brand-600">{{ $article->title }}</a></li>
            @endforeach
        </ul>
    @endif
</x-portal-layout>
