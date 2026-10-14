<x-admin-layout :title="$article->exists ? 'Edit article' : 'New article'">
    @include('admin.support._nav')
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $article->exists ? 'Edit article' : 'New article' }}</h1>
        @if ($article->exists && $article->isVisible())
            <a href="{{ route('kb.article', $article) }}" class="text-sm text-brand-600" target="_blank" rel="noopener">View it as a client</a>
        @endif
    </div>
    @if ($categories->isEmpty())
        <p class="text-sm text-ink-500">Add a <a href="{{ route('admin.kb.index') }}" class="text-brand-600">category</a> first.</p>
    @else
        <form method="POST" action="{{ $article->exists ? route('admin.kb-articles.update', $article) : route('admin.kb-articles.store') }}" class="max-w-3xl space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            @if ($article->exists) @method('PUT') @endif
            <label class="block">Title
                <input name="title" value="{{ old('title', $article->title) }}" required maxlength="255" placeholder="How do I point my domain at my hosting?" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="block sm:col-span-2">Category
                    <select name="kb_category_id" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        @foreach ($categories as $parent)
                            <option value="{{ $parent->id }}" @selected((string) old('kb_category_id', $article->kb_category_id) === (string) $parent->id)>{{ $parent->name }}</option>
                            @foreach ($parent->children as $child)
                                <option value="{{ $child->id }}" @selected((string) old('kb_category_id', $article->kb_category_id) === (string) $child->id)>{{ $parent->name }} › {{ $child->name }}</option>
                            @endforeach
                        @endforeach
                    </select>
                </label>
                <label class="block">Order
                    <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $article->sort_order ?? 0) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
            </div>
            <label class="block">Article (Markdown)
                <textarea name="body" rows="16" required class="mt-1 w-full rounded-md px-3 py-2 font-mono text-xs ring-1 ring-ink-300">{{ old('body', $article->body) }}</textarea>
                <span class="text-ink-500"># Heading, **bold**, *italic*, [link](https://example.com), - list items, `code` and ``` code blocks.</span>
            </label>
            <label class="block">Tags (optional)
                <input name="tags" value="{{ old('tags', $article->tags) }}" maxlength="255" placeholder="dns, nameservers" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">Comma separated. Searches match them too.</span>
            </label>
            <label class="flex items-center gap-2"><input type="checkbox" name="published" value="1" @checked(old('published', $article->published))> Published: clients can see it</label>
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ $article->exists ? 'Save' : 'Add article' }}</button>
        </form>
        @if ($article->exists)
            <p class="mt-3 text-sm text-ink-500">{{ $article->views }} views · {{ $article->helpful }} of {{ $article->helpful + $article->not_helpful }} found it helpful</p>
            <form method="POST" action="{{ route('admin.kb-articles.destroy', $article) }}" class="mt-4" onsubmit="return confirm('Delete this article?')">
                @csrf @method('DELETE')
                <button class="rounded-full px-4 py-2 text-sm text-red-700 ring-1 ring-red-200 hover:bg-red-50">Delete article</button>
            </form>
        @endif
    @endif
</x-admin-layout>
