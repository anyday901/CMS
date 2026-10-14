<x-admin-layout title="Announcements">
    @include('admin.support._nav')
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Announcements</h1>
        <a href="{{ route('announcements.index') }}" class="text-sm text-brand-600" target="_blank">View them as a client</a>
        <a href="{{ route('admin.announcements.create') }}" class="ml-auto rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">New announcement</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">News for clients. The latest three show on the portal home page, and all of them at <code>/announcements</code> with an RSS feed. Date one ahead to publish it then.</p>
    @if ($announcements->isEmpty())
        <p class="text-sm text-ink-500">No announcements yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Title</th><th class="px-4 py-2">Date</th><th class="px-4 py-2"><span class="sr-only">Status</span></th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($announcements as $announcement)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.announcements.edit', $announcement) }}" class="text-brand-600">{{ $announcement->title }}</a></td>
                            <td class="whitespace-nowrap px-4 py-2">{{ $announcement->published_at->format('M j, Y g:ia') }}</td>
                            <td class="px-4 py-2 text-right">
                                @if (! $announcement->published)<x-status-badge status="draft" />@elseif ($announcement->published_at->isFuture())<x-status-badge status="scheduled" />@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $announcements->links() }}</div>
    @endif
</x-admin-layout>
