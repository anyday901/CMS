<x-admin-layout title="Knowledge base">
    @include('admin.support._nav')
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Knowledge base</h1>
        <a href="{{ route('kb.index') }}" class="text-sm text-brand-600" target="_blank">View it as a client</a>
        <a href="{{ route('admin.kb-articles.create', array_filter(['category' => $filter])) }}" class="ml-auto rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">New article</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Articles are written in Markdown and anyone can read them at <code>/knowledgebase</code>, with search. Put articles in categories, and categories one level inside another.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="space-y-6 md:col-span-2">
            <form method="GET" class="flex items-center gap-2 text-sm">
                <label class="flex items-center gap-1.5">Category
                    <select name="category" onchange="this.form.submit()" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
                        <option value="">All categories</option>
                        @foreach ($categories as $parent)
                            <option value="{{ $parent->id }}" @selected($filter === $parent->id)>{{ $parent->name }}</option>
                            @foreach ($parent->children as $child)
                                <option value="{{ $child->id }}" @selected($filter === $child->id)>— {{ $child->name }}</option>
                            @endforeach
                        @endforeach
                    </select>
                </label>
            </form>
            @if ($articles->isEmpty())
                <p class="text-sm text-ink-500">No articles yet.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Article</th><th class="px-4 py-2">Category</th><th class="px-4 py-2 text-right">Views</th><th class="px-4 py-2 text-right">Helpful</th><th class="px-4 py-2"><span class="sr-only">Status</span></th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($articles as $article)
                                <tr>
                                    <td class="px-4 py-2"><a href="{{ route('admin.kb-articles.edit', $article) }}" class="text-brand-600">{{ $article->title }}</a></td>
                                    <td class="px-4 py-2 text-ink-600">{{ $article->category->parent ? $article->category->parent->name.' › ' : '' }}{{ $article->category->name }}</td>
                                    <td class="px-4 py-2 text-right">{{ $article->views }}</td>
                                    <td class="px-4 py-2 text-right">{{ $article->helpful }} / {{ $article->helpful + $article->not_helpful }}</td>
                                    <td class="px-4 py-2 text-right">@unless ($article->published)<x-status-badge status="draft" />@endunless</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $articles->links() }}
            @endif
        </div>

        <div class="space-y-6">
            <div>
                <h2 class="mb-2 font-semibold">Categories</h2>
                @if ($categories->isEmpty())
                    <p class="text-sm text-ink-500">Add a category before the first article.</p>
                @else
                    <ul class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
                        @foreach ($categories as $parent)
                            <li class="px-4 py-2"><a href="{{ route('admin.kb-categories.edit', $parent) }}" class="text-brand-600">{{ $parent->name }}</a> <span class="text-ink-500">{{ $parent->articles_count }}{{ $parent->visible ? '' : ' · hidden' }}</span>
                                @foreach ($parent->children as $child)
                                    <div class="pl-4">— <a href="{{ route('admin.kb-categories.edit', $child) }}" class="text-brand-600">{{ $child->name }}</a>@unless ($child->visible) <span class="text-ink-500">· hidden</span>@endunless</div>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <form method="POST" action="{{ route('admin.kb-categories.store') }}" class="space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                @csrf
                <h2 class="font-semibold">Add a category</h2>
                @include('admin.kb._category-fields')
                <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add category</button>
            </form>
        </div>
    </div>
</x-admin-layout>
