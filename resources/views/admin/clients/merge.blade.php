@php use App\Support\Money; @endphp
<x-admin-layout title="Merge client">
    <p class="mb-2 text-sm"><a href="{{ route('admin.clients.show', $client) }}" class="text-brand-600">{{ $client->fullName() }}</a></p>
    <h1 class="mb-2 text-2xl font-semibold">Merge {{ $client->fullName() }} into another client</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">For a client with two accounts. Everything on this account moves to the one you pick, and this account (#{{ $client->id }}, {{ $client->email }}) is deleted. Its details are kept in a note on the other account.</p>

    <div class="mb-6 grid max-w-3xl gap-3 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-3">
        @foreach (['services' => 'Services', 'invoices' => 'Invoices', 'orders' => 'Orders', 'tickets' => 'Tickets', 'contacts' => 'Contacts', 'notes' => 'Notes'] as $key => $label)
            <div><div class="text-ink-500">{{ $label }}</div><span class="font-semibold">{{ $counts[$key] }}</span></div>
        @endforeach
        <div><div class="text-ink-500">Credit balance</div><span class="font-semibold">{{ Money::format($client->credit_balance, $client->currency) }}</span></div>
        <div class="sm:col-span-2 text-ink-500">Payments, emails, logins, to-dos and the activity log move too.</div>
    </div>

    <form method="GET" action="{{ route('admin.clients.merge', $client) }}" role="search" class="mb-4 flex max-w-3xl gap-2 text-sm">
        <label class="sr-only" for="merge-search">Find the client to merge into</label>
        <input id="merge-search" type="search" name="q" value="{{ $query }}" placeholder="Find the client to keep: name, company, email or number" class="w-full rounded-full px-4 py-2 ring-1 ring-ink-300">
        <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Find</button>
    </form>

    @if ($query !== '' && ! $into)
        @if ($matches->isEmpty())
            <p class="text-sm text-ink-500">No other client matches.</p>
        @else
            <div class="max-w-3xl divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
                @foreach ($matches as $match)
                    <a href="{{ route('admin.clients.merge', [$client, 'q' => $query, 'into' => $match->id]) }}" class="flex flex-wrap items-center gap-x-3 px-4 py-3 hover:bg-ink-50">
                        <span class="text-ink-500">#{{ $match->id }}</span>
                        <span class="font-medium text-brand-700">{{ $match->fullName() }}</span>
                        @if ($match->company)<span>{{ $match->company }}</span>@endif
                        <span class="text-ink-500">{{ $match->email }}</span>
                        <x-status-badge :status="$match->status" class="ml-auto" />
                    </a>
                @endforeach
            </div>
        @endif
    @endif

    @if ($into)
        <form method="POST" action="{{ route('admin.clients.merge.store', $client) }}" class="max-w-3xl space-y-4 rounded-lg border border-amber-300 bg-white p-6 text-sm">
            @csrf
            <input type="hidden" name="into" value="{{ $into->id }}">
            <h2 class="text-base font-semibold">Merge into #{{ $into->id }} {{ $into->fullName() }}{{ $into->company ? ', '.$into->company : '' }}</h2>
            <p class="text-ink-600">{{ $into->email }} · {{ $into->services()->count() }} services · {{ Money::format($into->credit_balance, $into->currency) }} credit · <a href="{{ route('admin.clients.show', $into) }}" class="text-brand-600" target="_blank">open in a new tab</a></p>
            @if ($problem)
                <p class="rounded-md bg-red-50 px-3 py-2 text-red-800">{{ $problem }}</p>
            @else
                <ul class="list-inside list-disc space-y-1 text-ink-700">
                    <li>{{ $into->fullName() }} keeps their login, details, group and email preferences.</li>
                    <li>{{ $client->fullName() }}'s contacts move over; one with an email already on the account is combined with that contact.</li>
                    @if ($client->credit_balance)<li>{{ Money::format($client->credit_balance, $client->currency) }} of credit is added to {{ $into->fullName() }}'s balance.</li>@endif
                    <li>{{ $client->fullName() }} can no longer sign in with {{ $client->email }}.</li>
                </ul>
                <label for="merge-keep-contact" class="flex items-start gap-2"><input id="merge-keep-contact" type="checkbox" name="keep_as_contact" value="1" class="mt-1" checked>
                    <span>Add {{ $client->fullName() }} ({{ $client->email }}) as a contact on {{ $into->fullName() }}'s account, without a portal login</span></label>
                <label for="merge-confirm" class="flex items-start gap-2"><input id="merge-confirm" type="checkbox" name="confirm" value="1" class="mt-1">
                    <span class="font-medium">I understand account #{{ $client->id }} is deleted and this can't be undone</span></label>
                <div class="flex flex-wrap items-center gap-3">
                    <button class="rounded-md bg-red-700 px-3 py-1.5 font-medium text-white hover:bg-red-600">Merge and delete #{{ $client->id }}</button>
                    <a href="{{ route('admin.clients.merge', [$into, 'q' => $client->email, 'into' => $client->id]) }}" class="text-brand-600">Merge the other way instead</a>
                </div>
            @endif
        </form>
    @endif
</x-admin-layout>
