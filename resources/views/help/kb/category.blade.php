<x-portal-layout :title="$category->name" :store="true">
    @include('help._nav')
    <p class="mb-2 text-sm text-ink-500"><a href="{{ route('kb.index') }}" class="text-brand-600">Knowledge base</a>@if ($category->parent) › <a href="{{ route('kb.category', $category->parent) }}" class="text-brand-600">{{ $category->parent->name }}</a>@endif</p>
    <h1 lang="{{ $category->shownLanguage() }}" class="mb-2 text-2xl font-semibold">{{ $category->name }}</h1>
    @if ($category->description)<p class="mb-4 text-sm text-ink-600">{{ $category->description }}</p>@endif
    @include('help.kb._search')

    @if ($children->isNotEmpty())
        <div class="mb-6 grid gap-3 sm:grid-cols-2">
            @foreach ($children as $child)
                <a href="{{ route('kb.category', $child) }}" class="block rounded-lg border border-ink-200 bg-white px-4 py-3 text-sm hover:ring-1 hover:ring-brand-300">
                    <span class="font-semibold">{{ $child->name }}</span> <span class="text-ink-500">({{ $child->articles_count }})</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($articles->isEmpty())
        <p class="text-sm text-ink-500">No articles in this category yet.</p>
    @else
        <div class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
            @foreach ($articles as $article)
                <div class="px-4 py-3">
                    <a href="{{ route('kb.article', $article) }}" class="font-medium text-brand-600">{{ $article->title }}</a>
                    <p class="text-ink-600">{{ App\Support\Markdown::excerpt($article->body, 160) }}</p>
                </div>
            @endforeach
        </div>
    @endif
</x-portal-layout>
