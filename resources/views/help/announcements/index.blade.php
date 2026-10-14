<x-portal-layout title="Announcements" :store="true">
    @include('help._nav')
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Announcements</h1>
        <a href="{{ route('announcements.rss') }}" class="ml-auto rounded-full px-3 py-1 text-sm text-ink-600 ring-1 ring-ink-300 hover:bg-ink-100">RSS feed</a>
    </div>
    @if ($announcements->isEmpty())
        <p class="text-sm text-ink-500">No announcements yet.</p>
    @else
        <div class="space-y-4">
            @foreach ($announcements as $announcement)
                <article class="rounded-lg border border-ink-200 bg-white p-5 text-sm">
                    <p class="text-ink-500">{{ $announcement->published_at->format('M j, Y') }}</p>
                    <h2 class="mb-1 font-display text-lg font-semibold"><a href="{{ route('announcements.show', $announcement) }}" class="hover:text-brand-600">{{ $announcement->title }}</a></h2>
                    <p class="text-ink-700">{{ App\Support\Markdown::excerpt($announcement->body, 300) }}</p>
                    <a href="{{ route('announcements.show', $announcement) }}" class="mt-2 inline-block text-brand-600">Read more</a>
                </article>
            @endforeach
        </div>
        <div class="mt-6">{{ $announcements->links() }}</div>
    @endif
</x-portal-layout>
