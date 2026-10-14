<x-admin-layout title="Escalation rules">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Escalation rules</h1>
        <a href="{{ route('admin.escalation-rules.create') }}" class="ml-auto rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">New rule</a>
    </div>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">Every five minutes, each rule in turn looks for open tickets that match it and changes them: move them, change their status or priority, assign them, email staff or reply to the client. A rule changes a ticket once, and can again after the next reply. Response time targets (SLAs) are set on each <a href="{{ route('admin.departments.index') }}" class="text-brand-600">department</a>; a rule can act on tickets past theirs.</p>
    @if ($rules->isEmpty())
        <p class="text-sm text-ink-500">No rules yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Rule</th><th class="px-4 py-2">When a ticket is</th><th class="px-4 py-2">Then</th><th class="px-4 py-2"><span class="sr-only">Status</span></th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($rules as $rule)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.escalation-rules.edit', $rule) }}" class="text-brand-600">{{ $rule->name }}</a></td>
                            <td class="px-4 py-2">{{ ucfirst(implode(', ', $rule->conditionSummary()) ?: 'any open ticket') }}</td>
                            <td class="px-4 py-2">{{ ucfirst(implode(', ', $rule->actionSummary())) }}</td>
                            <td class="px-4 py-2 text-right">@unless ($rule->active)<x-status-badge status="off" />@endunless</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-admin-layout>
