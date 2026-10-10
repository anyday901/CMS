<x-portal-layout title="Choose a password">
    <div class="mx-auto mt-16 max-w-sm rounded-3xl bg-white p-7 shadow-[0_10px_30px_-12px_rgb(14_124_107/0.35)]">
        <div class="mb-4 flex items-center gap-2 font-display text-base font-extrabold"><span class="brand-mark"></span>{{ config('app.name') }}</div>
        <h1 class="mb-6 text-2xl">Choose a password</h1>
        <form method="POST" action="{{ route('portal.password.update') }}" class="space-y-4 text-sm">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label class="block">Email
                <input type="email" name="email" value="{{ old('email', $email) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">New password (at least 10 characters)
                <input type="password" name="password" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Confirm new password
                <input type="password" name="password_confirmation" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save password</button>
        </form>
    </div>
</x-portal-layout>
