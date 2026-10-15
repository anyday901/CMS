<x-admin-layout :title="$name.' portal text'">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $name }} portal text</h1>
        <a href="{{ route('admin.languages.index') }}" class="ml-auto text-sm text-brand-600">All languages</a>
    </div>
    <p class="mb-4 max-w-3xl text-sm text-ink-600">Write your own wording in the right-hand column; leave it blank to use CMS's. Words starting with a colon, such as <code class="rounded bg-ink-100 px-1">:name</code>, are filled in by the portal, so keep them.@unless ($offered) {{ $name }} isn't offered to clients yet; add it under Settings › Languages.@endunless</p>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 text-sm">
        <label class="block">Search
            <input type="search" name="q" value="{{ $search }}" class="mt-1 block w-64 rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        <label class="block">Show
            <select name="show" class="mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300">
                <option value="">Everything</option>
                <option value="missing" @selected($filter === 'missing')>Not translated</option>
                <option value="changed" @selected($filter === 'changed')>Changed by you</option>
            </select>
        </label>
        <button class="rounded-full bg-white px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-50">Filter</button>
    </form>

    <form method="POST" action="{{ route('admin.languages.update', $locale) }}" class="text-sm">
        @csrf @method('PUT')
        <div class="overflow-hidden rounded-lg border border-ink-200 bg-white">
            <table class="w-full table-fixed">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="w-1/3 px-4 py-2 font-normal">English</th><th class="w-1/3 px-4 py-2 font-normal">{{ $locale === 'en' ? 'As shipped' : 'CMS translation' }}</th><th class="px-4 py-2 font-normal">Your wording</th></tr></thead>
                <tbody class="divide-y divide-ink-100 align-top">
                    @forelse ($rows as $i => $row)
                        <tr>
                            <td class="px-4 py-2 break-words">{{ $row['key'] }}</td>
                            <td class="px-4 py-2 break-words text-ink-600">{{ $locale === 'en' ? $row['key'] : ($row['shipped'] ?? '—') }}</td>
                            <td class="px-4 py-2">
                                <input type="hidden" name="rows[{{ $i }}][key]" value="{{ $row['key'] }}">
                                <textarea name="rows[{{ $i }}][value]" rows="{{ strlen($row['key']) > 80 ? 3 : 1 }}" aria-label="Your wording for: {{ $row['key'] }}" class="block w-full rounded-md px-2 py-1 ring-1 ring-ink-300">{{ $row['override'] }}</textarea>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-4 text-ink-600">Nothing matches.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-4">
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save this page</button>
            {{ $rows->links() }}
        </div>
    </form>
</x-admin-layout>
