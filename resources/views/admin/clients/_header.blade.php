{{-- The client's name, actions and pinned notes, at the top of the client page and the pages shown inside it. --}}
<div class="mb-1 flex flex-wrap items-center gap-3">
    <h1 class="text-2xl font-semibold">{{ $client->fullName() }}</h1>
    <x-status-badge :status="$client->status" />
    <x-client-group-badge :group="$client->group" />
    <div class="ml-auto flex flex-wrap items-center gap-2">
        @can('manage-clients')
            <form method="POST" action="{{ route('admin.clients.password-link', $client) }}">@csrf
                <button class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Email portal password link</button>
            </form>
            @if ($client->canLogIn())
                <form method="POST" action="{{ route('admin.clients.login-as', $client) }}">@csrf
                    <button class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Log in as client</button>
                </form>
            @endif
            <a href="{{ route('admin.clients.merge', $client) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Merge</a>
            <a href="{{ route('admin.clients.edit', $client) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Edit</a>
        @endcan
        @can('manage-billing')
            <a href="{{ route('admin.invoices.create', $client) }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">New invoice</a>
        @endcan
        @can('manage-clients')
            <a href="{{ route('admin.services.create', $client) }}" class="rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">Add service</a>
        @endcan
    </div>
</div>

<p class="mb-5 text-sm text-ink-500">{{ collect([$client->email, collect([$client->city, $client->country])->filter()->join(', '), 'client since '.$client->created_at->format('M j, Y')])->filter()->join(' · ') }}</p>

@foreach ($client->clientNotes->where('pinned', true) as $note)
    <div class="mb-3 flex gap-3 rounded-md border border-amber-200 bg-sun-300/30 px-4 py-3 text-sm text-amber-950">
        <span class="font-semibold">Note</span>
        <div class="grow">{!! nl2br(e($note->body)) !!}<span class="mt-1 block text-xs text-amber-800">{{ $note->user?->name ?? 'Staff' }}, {{ $note->created_at->format('M j, Y') }} · <a href="{{ route('admin.clients.show', [$client, 'tab' => 'notes']) }}#notes" class="underline">all notes</a></span></div>
    </div>
@endforeach
