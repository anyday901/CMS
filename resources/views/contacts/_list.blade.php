{{-- $contacts, $editRoute (route name), $destroyRoute (route name), $inviteRoute (route name, optional), $twoFactorRoute (route name, admin only) --}}
@if ($contacts->isEmpty())
    <p class="text-sm text-ink-500">No contacts yet.</p>
@else
    <div class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
        @foreach ($contacts as $item)
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $item->fullName() }}</div>
                    <div class="text-ink-500">{{ $item->email }}@if ($item->phone) · {{ $item->phone }}@endif · {{ $item->receives() }}</div>
                    <div class="text-ink-500">{{ $item->access() }}@if ($item->portal_access && $item->hasTwoFactor()) · two-factor on @endif @if ($item->last_login_at) · last signed in {{ $item->last_login_at->format('M j, Y') }}@endif
                        @if ($item->portal_access && ($others = $item->siblings()->count())) · same login on {{ $others }} other {{ Str::plural('account', $others) }}@endif</div>
                </div>
                @if ($editRoute)
                    @if (($inviteRoute ?? null) && $item->portal_access && ! $item->password)
                        <form method="POST" action="{{ route($inviteRoute, $item) }}">@csrf
                            <button class="rounded-full px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Send invite again</button>
                        </form>
                    @endif
                    @if (($twoFactorRoute ?? null) && $item->hasTwoFactor())
                        <form method="POST" action="{{ route($twoFactorRoute, $item) }}" onsubmit="return confirm('Turn off two-factor authentication for this contact?')">@csrf @method('DELETE')
                            <button class="rounded-full px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Turn off two-factor</button>
                        </form>
                    @endif
                    <a href="{{ route($editRoute, $item) }}" class="rounded-full px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Edit</a>
                    <form method="POST" action="{{ route($destroyRoute, $item) }}" onsubmit="return confirm('Remove this contact?')">
                        @csrf @method('DELETE')
                        <button class="rounded-full px-3 py-1 text-red-700 ring-1 ring-red-300 hover:bg-red-50">Remove</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
@endif
