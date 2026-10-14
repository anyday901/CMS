@php use Illuminate\Support\Number; @endphp
<x-admin-layout title="Downloads">
    @include('admin.support._nav')
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Downloads</h1>
        <a href="{{ route('downloads.index') }}" class="text-sm text-brand-600" target="_blank">View them as a client</a>
        @if ($categories->isNotEmpty())
            <a href="{{ route('admin.downloads.create') }}" class="ml-auto rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">New download</a>
        @endif
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Files for clients at <code>/downloads</code>. A download can be for everyone, for logged-in clients only, or for clients with an active service of chosen products.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="space-y-6 md:col-span-2">
            @forelse ($categories as $item)
                <section>
                    <h2 class="mb-2 font-semibold"><a href="{{ route('admin.download-categories.edit', $item) }}" class="text-brand-600">{{ $item->name }}</a></h2>
                    @if ($item->downloads->isEmpty())
                        <p class="text-sm text-ink-500">Empty. <a href="{{ route('admin.downloads.create', ['category' => $item->id]) }}" class="text-brand-600">Add a file</a></p>
                    @else
                        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                            <table class="min-w-full text-sm">
                                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Title</th><th class="px-4 py-2">File</th><th class="px-4 py-2">Who</th><th class="px-4 py-2 text-right">Downloads</th></tr></thead>
                                <tbody class="divide-y divide-ink-100">
                                    @foreach ($item->downloads as $download)
                                        <tr>
                                            <td class="px-4 py-2"><a href="{{ route('admin.downloads.edit', $download) }}" class="text-brand-600">{{ $download->title }}</a></td>
                                            <td class="px-4 py-2 text-ink-600">{{ $download->file_name }} · {{ Number::fileSize($download->size) }}</td>
                                            <td class="px-4 py-2">{{ $download->audience() }}</td>
                                            <td class="px-4 py-2 text-right">{{ $download->downloads }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @empty
                <p class="text-sm text-ink-500">Add a category before the first download.</p>
            @endforelse
        </div>

        <form method="POST" action="{{ route('admin.download-categories.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a category</h2>
            @include('admin.downloads._category-fields')
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add category</button>
        </form>
    </div>
</x-admin-layout>
