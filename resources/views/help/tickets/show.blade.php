<x-portal-layout :title="'Ticket #'.$ticket->id" :store="true">
    <p class="mb-4 text-sm text-ink-600">This page is private to you, {{ $ticket->requesterFirstName() }}: anyone with its link can read and answer the ticket, so don't share it. You can also reply to our emails.</p>
    @include('tickets._conversation', ['urls' => [
        'close' => route('tickets.guest.close', [$ticket, $key]),
        'reply' => route('tickets.guest.reply', [$ticket, $key]),
        'feedback' => route('tickets.guest.feedback', [$ticket, $key]),
        'download' => fn ($file) => route('tickets.guest.attachment', [$ticket, $key, $file]),
    ]])
</x-portal-layout>
