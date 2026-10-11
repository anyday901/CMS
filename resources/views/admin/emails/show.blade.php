<x-admin-layout :title="$email->subject">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $email->subject }}</h1>
        <x-status-badge :status="$email->status" />
        @if ($email->status !== 'sent')
            @can('manage-clients')
                <form method="POST" action="{{ route('admin.emails.resend', $email) }}" class="ml-auto">@csrf
                    <button class="rounded-full px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Send again</button>
                </form>
            @endcan
        @endif
    </div>
    <div class="mb-6 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm md:grid-cols-3">
        <div><div class="text-ink-500">To</div>{{ $email->to }}@if ($email->client) (<a href="{{ route('admin.clients.show', $email->client) }}" class="text-brand-600">{{ $email->client->fullName() }}</a>)@endif
            @if ($email->cc)<div class="mt-1 text-ink-500">Copy to {{ implode(', ', $email->cc) }}</div>@endif</div>
        <div><div class="text-ink-500">Queued</div>{{ $email->created_at->format('M j, Y g:ia') }}</div>
        <div><div class="text-ink-500">Sent</div>{{ $email->sent_at?->format('M j, Y g:ia') ?? '—' }}@if ($email->attach_invoice_pdf) · invoice PDF attached @endif</div>
    </div>
    @if ($email->error)<p class="mb-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">{{ $email->error }}</p>@endif
    <div class="prose max-w-3xl rounded-lg border border-ink-200 bg-white p-6 text-sm [&_a]:text-brand-600 [&_p]:mb-3">{!! $html !!}</div>
</x-admin-layout>
