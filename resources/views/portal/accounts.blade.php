<x-portal-layout title="Choose an account">
    <h1 class="mb-2 text-2xl font-semibold">Choose an account</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Your login, {{ $current->email }}, reaches more than one account. Pick the one to work in; you can switch any time from the menu.</p>
    <ul class="grid max-w-3xl gap-3 sm:grid-cols-2">
        @foreach ($accounts as $account)
            <li>
                <form method="POST" action="{{ route('portal.accounts.switch', $account) }}">
                    @csrf
                    <button class="flex w-full flex-col items-start rounded-lg border bg-white p-4 text-left text-sm hover:border-brand-500 {{ $account->is($current) ? 'border-brand-500 ring-1 ring-brand-500' : 'border-ink-200' }}">
                        <span class="font-semibold">{{ $account->accountName() }}</span>
                        @if ($account->client->company)<span class="text-ink-500">{{ $account->client->fullName() }}</span>@endif
                        <span class="mt-2 text-ink-600">You can: {{ collect(App\Models\ClientContact::PERMISSIONS)->only($account->permissions ?? [])->map(fn ($label) => strtolower(strtok($label, ':')))->join(', ', ' and ') ?: 'sign in only' }}</span>
                        @if ($account->is($current))<span class="mt-2 text-xs font-semibold text-brand-700">Open now</span>@endif
                    </button>
                </form>
            </li>
        @endforeach
    </ul>
</x-portal-layout>
