<x-admin-layout title="My account">
    <h1 class="mb-6 text-2xl font-semibold">My account</h1>
    <div class="grid gap-6 md:grid-cols-2">
        @include('two-factor._manage', ['account' => $user, 'routes' => 'admin.account.two-factor'])

        <form method="POST" action="{{ route('admin.account.password') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            @csrf
            @method('PUT')
            <h2 class="font-semibold">Change password</h2>
            <p class="text-ink-600">Signed in as {{ $user->name }} ({{ $user->email }}), {{ $user->role->label() }}.</p>
            <label class="block">Current password<input type="password" name="current_password" required autocomplete="current-password" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
            <label class="block">New password<input type="password" name="password" required minlength="12" autocomplete="new-password" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
            <label class="block">Confirm new password<input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Change password</button>
        </form>
    </div>

    <h2 class="mt-8 mb-3 text-lg font-semibold">Your recent logins</h2>
    @include('security._logins', ['logins' => $logins])
</x-admin-layout>
