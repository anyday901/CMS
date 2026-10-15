{{-- $logins, $showWho (bool), $banLinks (bool, admin only) --}}
@if ($logins->isEmpty())
    <p class="text-sm text-ink-500">{{ __('No logins yet.') }}</p>
@else
    <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-ink-50 text-left text-ink-500">
                <tr>
                    <th class="px-4 py-2">{{ __('When') }}</th>
                    @if ($showWho ?? false)<th class="px-4 py-2">{{ __('Who') }}</th>@endif
                    <th class="px-4 py-2">{{ __('Result') }}</th>
                    <th class="px-4 py-2">{{ __('IP address') }}</th>
                    <th class="px-4 py-2">{{ __('Browser') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($logins as $login)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-2">{{ $login->created_at->translatedFormat('M j, Y g:i A') }}</td>
                        @if ($showWho ?? false)
                            <td class="px-4 py-2">
                                @php $url = ($banLinks ?? false) ? App\Security\LoginSecurity::accountUrl($login) : null; @endphp
                                @if ($url)<a href="{{ $url }}" class="text-brand-600">{{ $login->who() }}</a>@else{{ $login->who() }}@endif
                                @if ($banLinks ?? false)<span class="block text-xs text-ink-500">{{ $login->area === 'admin' ? 'Admin' : 'Portal' }} · {{ $login->email }}</span>@endif
                            </td>
                        @endif
                        <td class="whitespace-nowrap px-4 py-2 {{ $login->succeeded() ? 'text-green-700' : 'text-red-700' }}">{{ __($login->outcomeLabel()) }}</td>
                        <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                            {{ $login->ip_address ?? '—' }}
                            @if (($banLinks ?? false) && $login->ip_address)
                                <a href="{{ route('admin.security.show', ['q' => $login->ip_address]) }}" class="ml-1 font-sans text-brand-600">all</a>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-ink-600">{{ $login->browser() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
