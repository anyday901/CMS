<x-portal-layout :title="$announcement->title" :store="true">
    @include('help._nav')
    <p class="mb-2 text-sm"><a href="{{ route('announcements.index') }}" class="text-brand-600">{{ __('All announcements') }}</a></p>
    <article lang="{{ $announcement->shownLanguage() }}" class="max-w-3xl rounded-lg border border-ink-200 bg-white p-6">
        <p class="text-sm text-ink-500">{{ $announcement->published_at->translatedFormat('l, F j, Y') }}</p>
        <h1 class="mb-4 text-2xl font-semibold">{{ $announcement->title }}</h1>
        <div class="prose-content text-sm">{!! App\Support\Markdown::html($announcement->body) !!}</div>
    </article>
</x-portal-layout>
