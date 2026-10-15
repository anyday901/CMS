<x-admin-layout title="Mass mail">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Mass mail</h1>
        <a href="{{ route('admin.mass-mail.create') }}" class="ml-auto rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">New email</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Email many clients at once, picked by status, group, country, currency or the products they have. Marketing emails only go to clients who agreed to them, with their unsubscribe link.</p>

    @if ($mailings->isEmpty())
        <p class="text-sm text-ink-500">Nothing sent yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Subject</th><th class="px-4 py-2">Kind</th><th class="px-4 py-2">Recipients</th><th class="px-4 py-2">Sent</th><th class="px-4 py-2">By</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($mailings as $item)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.mass-mail.show', $item) }}" class="font-medium text-brand-600">{{ $item->subject }}</a></td>
                            <td class="px-4 py-2">{{ $item->marketing ? 'Marketing' : 'Service notice' }}</td>
                            <td class="px-4 py-2">{{ number_format($item->recipients) }}<span class="block text-ink-500">{{ $item->sent_count }} sent{{ $item->failed_count ? ", {$item->failed_count} not sent" : '' }}</span></td>
                            <td class="px-4 py-2 text-ink-500">{{ $item->status === 'sent' ? $item->sent_at?->format('M j, Y g:i a') : ucfirst($item->status) }}</td>
                            <td class="px-4 py-2 text-ink-500">{{ $item->user?->name ?? 'Deleted staff' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $mailings->links() }}</div>
    @endif
</x-admin-layout>
