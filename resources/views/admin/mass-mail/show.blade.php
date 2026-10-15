<x-admin-layout :title="$mail->subject">
    <p class="mb-2 text-sm"><a href="{{ route('admin.mass-mail.index') }}" class="text-brand-600">Mass mail</a></p>
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $mail->subject }}</h1>
        <a href="{{ route('admin.mass-mail.create', ['copy' => $mail->id]) }}" class="ml-auto text-sm text-brand-600">Send a copy</a>
    </div>
    <p class="mb-6 text-sm text-ink-600">{{ $mail->marketing ? 'Marketing email' : 'Service notice' }} by {{ $mail->user?->name ?? 'deleted staff' }}, {{ $mail->created_at->format('M j, Y g:i a') }}.</p>

    <div class="mb-6 grid gap-3 sm:grid-cols-4">
        @foreach (['Recipients' => $mail->status === 'sent' ? $mail->recipients : $counts->sum(), 'Sent' => $counts['sent'] ?? 0, 'Waiting' => $counts['queued'] ?? 0, 'Not sent' => $counts['failed'] ?? 0] as $label => $number)
            <div class="rounded-lg border border-ink-200 bg-white p-4"><p class="text-sm text-ink-500">{{ $label }}</p><p class="text-2xl font-semibold">{{ number_format($number) }}</p></div>
        @endforeach
    </div>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="rounded-lg border border-ink-200 bg-white text-sm md:col-span-2">
            <div class="prose-content px-4 py-3">{!! $html !!}</div>
        </div>
        <div class="h-fit rounded-lg border border-ink-200 bg-white p-4 text-sm">
            <h2 class="mb-2 font-semibold">Filters</h2>
            <dl class="space-y-1">
                @forelse ($mail->filters as $key => $value)
                    <div><dt class="inline text-ink-500">{{ Str::headline($key) }}:</dt> <dd class="inline">{{ implode(', ', (array) $value) }}</dd></div>
                @empty
                    <p class="text-ink-500">None</p>
                @endforelse
            </dl>
        </div>
    </div>

    <h2 class="mb-2 mt-8 text-lg font-semibold">Emails</h2>
    <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Client</th><th class="px-4 py-2">To</th><th class="px-4 py-2">Status</th></tr></thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($emails as $email)
                    <tr>
                        <td class="px-4 py-2">@if ($email->client)<a href="{{ route('admin.clients.show', $email->client) }}" class="text-brand-600">{{ $email->client->fullName() }}</a>@endif</td>
                        <td class="px-4 py-2">@can('manage-settings')<a href="{{ route('admin.emails.show', $email) }}" class="text-brand-600">{{ $email->to }}</a>@else{{ $email->to }}@endcan</td>
                        <td class="px-4 py-2">{{ ucfirst($email->status) }}@if ($email->error)<span class="block text-red-700">{{ $email->error }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-3 text-ink-500">Being queued.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $emails->links() }}</div>
</x-admin-layout>
