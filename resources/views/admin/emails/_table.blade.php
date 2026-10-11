<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">To</th><th class="px-4 py-2">Subject</th><th class="px-4 py-2">Status</th></tr></thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($emails as $email)
                <tr>
                    <td class="whitespace-nowrap px-4 py-2">{{ $email->created_at->format('M j, Y g:ia') }}</td>
                    <td class="px-4 py-2">{{ $email->to }}</td>
                    <td class="px-4 py-2">@can('manage-settings')<a href="{{ route('admin.emails.show', $email) }}" class="text-brand-600">{{ $email->subject }}</a>@else{{ $email->subject }}@endcan</td>
                    <td class="px-4 py-2"><x-status-badge :status="$email->status" /></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
