<x-portal-layout title="Projects">
    <h1 class="mb-6 text-2xl font-semibold">Projects</h1>
    @if ($projects->isEmpty())
        <p class="text-sm text-ink-500">You have no projects with us yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500">
                    <tr><th class="px-4 py-2">Project</th><th class="px-4 py-2">Progress</th><th class="px-4 py-2">Due</th><th class="px-4 py-2">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($projects as $project)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('portal.projects.show', $project) }}" class="text-brand-600">{{ $project->title }}</a></td>
                            <td class="whitespace-nowrap px-4 py-2">{{ $project->tasks_count ? "{$project->done_tasks_count} of {$project->tasks_count} tasks done" : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ $project->due_on?->format('M j, Y') ?? '—' }}</td>
                            <td class="px-4 py-2"><x-status-badge :status="$project->badge()" class="whitespace-nowrap">{{ $project->statusLabel() }}</x-status-badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-portal-layout>
