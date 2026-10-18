@php $date = $before->toDateString(); @endphp
<x-admin-layout title="Cleanup">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">System cleanup</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    @include('admin.system._nav')
    <p class="mb-6 max-w-3xl text-sm text-ink-600">Delete old logs and ticket attachments to keep the database and disk small. Pick a date to count what was made before it, tick what to delete, and delete. Invoices, payments, credit notes, credit and other financial records are never deleted here. Deleting can't be undone, so take a <a href="{{ route('admin.backups.index') }}" class="text-brand-600">backup</a> first if you might want something back.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <section class="rounded-lg border border-ink-200 bg-white text-sm md:col-span-2">
            <form method="GET" action="{{ route('admin.cleanup.show') }}" class="flex flex-wrap items-end gap-3 border-b border-ink-200 px-6 py-4">
                <label class="block">Made before
                    <input type="date" name="before" value="{{ $date }}" max="{{ today()->toDateString() }}" required class="mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <button class="rounded-full bg-white px-4 py-2 font-medium ring-1 ring-ink-300 hover:bg-ink-50">Count</button>
            </form>

            <form method="POST" action="{{ route('admin.cleanup.run') }}" onsubmit="return confirm('Delete the ticked items made before {{ $before->toFormattedDateString() }}? This can\'t be undone.')">
                @csrf @method('DELETE')
                <input type="hidden" name="before" value="{{ $date }}">
                <table class="w-full">
                    <thead class="text-left text-ink-500"><tr><th class="px-6 py-2 font-normal">Delete</th><th class="px-3 py-2 text-right font-normal">Before {{ $before->toFormattedDateString() }}</th></tr></thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($targets as $key => $label)
                            <tr>
                                <td class="px-6 py-2">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="targets[]" value="{{ $key }}" @checked(in_array($key, (array) old('targets', []), true)) @disabled($counts[$key] === 0)>
                                        {{ $label }}
                                    </label>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($counts[$key]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="flex items-center gap-3 border-t border-ink-200 px-6 py-4">
                    <button class="rounded-full bg-red-700 px-4 py-2 font-medium text-white hover:bg-red-600" @disabled(array_sum($counts) === 0)>Delete ticked</button>
                    <span class="text-ink-500">{{ number_format(array_sum($counts)) }} in all. The cleanup is written to the activity log.</span>
                </div>
            </form>
        </section>

        <aside class="space-y-3 self-start rounded-lg border border-ink-200 bg-white p-6 text-sm text-ink-600">
            <h2 class="text-base font-semibold text-ink-800">Automatic cleanup</h2>
            <p>Some logs are already trimmed every night: login history after the days set under Security, ticket email and texts after 90 days, webhook deliveries and task runs after 30 days.</p>
            <p>The activity and gateway logs are kept
                @if (blank($retentionDays))
                    forever.
                @else
                    for {{ $retentionDays }} days.
                @endif
                Change that with <strong>Activity log retention</strong> on the main settings page.</p>
            <p>The same cleanup runs from the command line: <code class="rounded bg-ink-100 px-1">php artisan system:cleanup --days=365</code> (add <code class="rounded bg-ink-100 px-1">--dry-run</code> to only count).</p>
        </aside>
    </div>
</x-admin-layout>
