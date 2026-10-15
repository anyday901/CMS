{{-- $contacts, $editRoute (route name), $destroyRoute (route name), $inviteRoute (route name, optional), $twoFactorRoute (route name, admin only) --}}
@if ($contacts->isEmpty())
    <p class="text-sm text-ink-500">{{ __('No contacts yet.') }}</p>
@else
    <div class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
        @foreach ($contacts as $item)
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $item->fullName() }}</div>
                    <div class="text-ink-500">{{ $item->email }}@if ($item->phone) · {{ $item->phone }}@endif · {{ __($item->receives()) }}</div>
                    @php
                        $allowed = array_values(array_intersect(array_keys(App\Models\ClientContact::PERMISSIONS), $item->permissions ?? []));
                        $what = $allowed === [] ? __('nothing yet') : implode(', ', array_map(fn ($key) => __($key), $allowed));
                    @endphp
                    <div class="text-ink-500">{{ ! $item->portal_access ? __('No portal login') : ($item->password === null ? __('Invited to sign in: :what', ['what' => $what]) : __('Signs in: :what', ['what' => $what])) }}@if ($item->portal_access && $item->hasTwoFactor()) · {{ __('two-factor on') }} @endif @if ($item->last_login_at) · {{ __('last signed in :date', ['date' => $item->last_login_at->translatedFormat('M j, Y')]) }}@endif
                        @if ($item->portal_access && ($others = $item->siblings()->count())) · {{ trans_choice('same login on :count other account|same login on :count other accounts', $others) }}@endif</div>
                </div>
                @if ($editRoute)
                    @if (($inviteRoute ?? null) && $item->portal_access && ! $item->password)
                        <form method="POST" action="{{ route($inviteRoute, $item) }}">@csrf
                            <button class="rounded-full px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Send invite again') }}</button>
                        </form>
                    @endif
                    @if (($twoFactorRoute ?? null) && $item->hasTwoFactor())
                        <form method="POST" action="{{ route($twoFactorRoute, $item) }}" onsubmit="return confirm({{ Js::from(__('Turn off two-factor authentication for this contact?')) }})">@csrf @method('DELETE')
                            <button class="rounded-full px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Turn off two-factor') }}</button>
                        </form>
                    @endif
                    <a href="{{ route($editRoute, $item) }}" class="rounded-full px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Edit') }}</a>
                    <form method="POST" action="{{ route($destroyRoute, $item) }}" onsubmit="return confirm({{ Js::from(__('Remove this contact?')) }})">
                        @csrf @method('DELETE')
                        <button class="rounded-full px-3 py-1 text-red-700 ring-1 ring-red-300 hover:bg-red-50">{{ __('Remove') }}</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
@endif
