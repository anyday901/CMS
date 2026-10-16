<x-portal-layout :title="__('Security')">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('Security') }}</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">
        @if ($contact)
            {{ __("You're signed in as :name, a contact on this account. These settings are for your own login.", ['name' => $contact->fullName()]) }}
        @else
            {{ __("Your login, and every login to your account, including your contacts'.") }}
        @endif
    </p>

    <div class="grid gap-6 md:grid-cols-2">
        @include('two-factor._manage', ['account' => $account, 'routes' => 'portal.security.two-factor'])

        @if ($contact)
            <form method="POST" action="{{ route('portal.security.password') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
                @csrf
                @method('PUT')
                <h2 class="font-semibold">{{ __('Change password') }}</h2>
                <label class="block">{{ __('Current password') }}<input type="password" name="current_password" required autocomplete="current-password" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
                <label class="block">{{ __('New password') }}<input type="password" name="password" required minlength="10" autocomplete="new-password" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
                <label class="block">{{ __('Confirm new password') }}<input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
                <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Change password') }}</button>
            </form>
        @else
            <div class="h-fit space-y-2 rounded-lg border border-ink-200 bg-white p-6 text-sm">
                <h2 class="font-semibold">{{ __('Password and your data') }}</h2>
                <p class="text-ink-600">{!! __('Change your password under :link.', ['link' => '<a href="'.e(route('portal.account.edit')).'" class="text-brand-600">'.e(__('Account')).'</a>']) !!}</p>
                <p class="text-ink-600">{{ __('Download a copy of everything we keep about your account, as a JSON file.') }}</p>
                <a href="{{ route('portal.account.export') }}" class="inline-block rounded-full px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Download my data') }}</a>
            </div>
        @endif
    </div>

    <h2 class="mt-8 mb-3 text-lg font-semibold">{{ __('Recent logins') }}</h2>
    <p class="mb-3 max-w-2xl text-sm text-ink-600">{{ __("If you see a login you don't recognise, change your password and turn on two-factor authentication.") }}</p>
    @include('security._logins', ['logins' => $logins, 'showWho' => ! $contact])
</x-portal-layout>
