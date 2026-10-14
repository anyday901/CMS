<x-portal-layout :title="$article->title" :store="true">
    @include('help._nav')
    <p class="mb-2 text-sm text-ink-500">
        <a href="{{ route('kb.index') }}" class="text-brand-600">Knowledge base</a>
        @if ($article->category->parent) › <a href="{{ route('kb.category', $article->category->parent) }}" class="text-brand-600">{{ $article->category->parent->name }}</a>@endif
        › <a href="{{ route('kb.category', $article->category) }}" class="text-brand-600">{{ $article->category->name }}</a>
    </p>
    <div class="grid gap-6 lg:grid-cols-3">
        <article class="rounded-lg border border-ink-200 bg-white p-6 lg:col-span-2">
            <h1 class="mb-1 text-2xl font-semibold">{{ $article->title }}</h1>
            <p class="mb-5 text-sm text-ink-500">Updated {{ $article->updated_at->format('M j, Y') }}</p>
            <div class="prose-content text-sm">{!! App\Support\Markdown::html($article->body) !!}</div>
            @if ($article->tagList())
                <p class="mt-6 flex flex-wrap gap-1.5 text-xs">@foreach ($article->tagList() as $tag)<a href="{{ route('kb.index', ['q' => $tag]) }}" class="rounded-full bg-ink-100 px-2.5 py-0.5 text-ink-700 hover:bg-ink-200">{{ $tag }}</a>@endforeach</p>
            @endif

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-ink-100 pt-4 text-sm">
                @if ($voted)
                    <span class="text-ink-600">Thanks for your feedback.</span>
                @else
                    <span class="text-ink-600">Was this article helpful?</span>
                    @foreach (['1' => 'Yes', '0' => 'No'] as $value => $label)
                        <form method="POST" action="{{ route('kb.vote', $article) }}">@csrf
                            <input type="hidden" name="helpful" value="{{ $value }}">
                            <button class="rounded-full px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">{{ $label }}</button>
                        </form>
                    @endforeach
                @endif
                <span class="ml-auto text-ink-500">{{ $article->helpful }} of {{ $article->helpful + $article->not_helpful }} found this helpful</span>
            </div>
        </article>

        <aside class="space-y-4 text-sm">
            @if ($related->isNotEmpty())
                <div class="rounded-lg border border-ink-200 bg-white p-4">
                    <h2 class="mb-2 font-semibold">More in {{ $article->category->name }}</h2>
                    <ul class="space-y-1">
                        @foreach ($related as $other)
                            <li><a href="{{ route('kb.article', $other) }}" class="text-brand-600">{{ $other->title }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="rounded-lg border border-ink-200 bg-white p-4">
                <h2 class="mb-1 font-semibold">Still need help?</h2>
                <p class="text-ink-600"><a href="{{ route('portal.tickets.create') }}" class="text-brand-600">Open a ticket</a> and our team will get back to you.</p>
            </div>
        </aside>
    </div>
</x-portal-layout>
