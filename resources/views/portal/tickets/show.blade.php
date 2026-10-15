<x-portal-layout :title="'Ticket #'.$ticket->id">
    @include('tickets._conversation', ['urls' => [
        'close' => route('portal.tickets.close', $ticket),
        'reply' => route('portal.tickets.reply', $ticket),
        'feedback' => route('portal.tickets.feedback', $ticket),
        'download' => fn ($file) => route('portal.tickets.attachment', [$ticket, $file]),
    ]])
</x-portal-layout>
