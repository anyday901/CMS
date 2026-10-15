{{-- Who opened $ticket: a link to the client, or the guest's name and address. --}}
@if ($ticket->isGuest())
    {{ $ticket->guest_name }} <span class="text-ink-500">{{ $ticket->guest_email }} · guest</span>
@elseif ($ticket->client)
    <a href="{{ route('admin.clients.show', $ticket->client) }}" class="text-brand-600">{{ $ticket->client->fullName() }}</a>
@endif
