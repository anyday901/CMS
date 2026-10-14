@php use Illuminate\Support\Number; @endphp
<x-portal-layout title="Downloads" :store="true">
    @include('help._nav')
    <h1 class="mb-6 text-2xl font-semibold">Downloads</h1>
    @if ($categories->isEmpty())
        <p class="text-sm text-ink-500">There are no downloads yet.</p>
    @endif
    @foreach ($categories as $category)
        <section class="mb-8">
            <h2 class="text-lg font-semibold">{{ $category->name }}</h2>
            @if ($category->description)<p class="mb-2 text-sm text-ink-600">{{ $category->description }}</p>@endif
            <div class="mt-2 divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
                @foreach ($category->downloads as $download)
                    @php $allowed = $download->allows($client); @endphp
                    <div class="flex flex-wrap items-center gap-4 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ $download->title }}</div>
                            @if ($download->description)<p class="text-ink-600">{{ $download->description }}</p>@endif
                            <p class="text-ink-500">{{ $download->file_name }} · {{ Number::fileSize($download->size) }}@if ($download->needsLogin()) · {{ $download->audience() }}@endif</p>
                        </div>
                        @if ($allowed)
                            <a href="{{ route('downloads.file', $download) }}" class="rounded-full bg-brand-600 px-4 py-1.5 font-medium text-white hover:bg-brand-500">Download</a>
                        @elseif ($client === null)
                            <a href="{{ route('downloads.file', $download) }}" class="rounded-full px-4 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Log in to download</a>
                        @else
                            <span class="rounded-full bg-ink-100 px-4 py-1.5 text-ink-500">For clients with {{ $download->products->pluck('name')->join(', ', ' or ') }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</x-portal-layout>
