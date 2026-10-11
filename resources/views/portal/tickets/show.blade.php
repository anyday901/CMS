@php use App\Enums\TicketStatus; @endphp
<x-portal-layout :title="'Ticket #'.$ticket->id">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">#{{ $ticket->id }} {{ $ticket->subject }}</h1>
        <x-status-badge :status="$ticket->status" />
        @if ($ticket->status !== TicketStatus::Closed)
            <form method="POST" action="{{ route('portal.tickets.close', $ticket) }}" class="ml-auto">@csrf
                <button class="rounded-full px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Close ticket</button>
            </form>
        @endif
    </div>
    <p class="mb-6 text-sm text-ink-500">{{ $ticket->department->name }} · opened {{ $ticket->created_at->format('M j, Y') }}</p>

    <div class="max-w-3xl space-y-4">
        @foreach ($ticket->messages as $message)
            @include('tickets._message', ['download' => fn ($file) => route('portal.tickets.attachment', [$ticket, $file])])
        @endforeach

        <form method="POST" action="{{ route('portal.tickets.reply', $ticket) }}" enctype="multipart/form-data" class="space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">{{ $ticket->status === TicketStatus::Closed ? 'Reply to reopen this ticket' : 'Reply' }}</h2>
            <textarea name="body" rows="5" required aria-label="Reply" class="block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('body') }}</textarea>
            @include('tickets._attachments')
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Send reply</button>
        </form>
    </div>
</x-portal-layout>
