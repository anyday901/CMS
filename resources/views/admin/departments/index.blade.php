<x-admin-layout title="Departments">
    <h1 class="mb-2 text-2xl font-semibold">Departments</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Clients pick a department when they open a ticket. Turn a department off to stop new tickets in it; its existing tickets stay. Set response time targets on a department to track overdue tickets, and act on them with <a href="{{ route('admin.escalation-rules.index') }}" class="text-brand-600">escalation rules</a>. Add fields clients fill in for a department's tickets under <a href="{{ route('admin.custom-fields.index', ['entity' => 'ticket']) }}" class="text-brand-600">Custom fields</a>.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($departments->isEmpty())
                <p class="text-sm text-ink-500">No departments yet. Add one so clients can open tickets.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">Tickets</th><th class="px-4 py-2">SLA</th><th class="px-4 py-2"><span class="sr-only">Status</span></th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($departments as $item)
                                <tr>
                                    <td class="px-4 py-2"><a href="{{ route('admin.departments.edit', $item) }}" class="text-brand-600">{{ $item->name }}</a></td>
                                    <td class="px-4 py-2">{{ $item->email ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $item->tickets_count }}</td>
                                    <td class="px-4 py-2 text-ink-600">{{ collect($item->sla_hours ?? [])->map(fn ($hours, $priority) => ucfirst($priority).' '.(float) $hours.'h')->join(', ') ?: '—' }}@if ($item->sla_hours && $item->sla_business_hours) <span class="text-ink-500">(business hours)</span>@endif</td>
                                    <td class="px-4 py-2 text-right">@unless ($item->active)<x-status-badge status="off" />@endunless</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.departments.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a department</h2>
            @include('admin.departments.fields')
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add department</button>
        </form>
    </div>
</x-admin-layout>
