<x-portal-layout title="Set your password">
    <div class="mx-auto mt-16 max-w-sm rounded-3xl bg-white p-7 shadow-[0_10px_30px_-12px_rgb(14_124_107/0.35)]">
        <div class="mb-4 flex items-center gap-2 font-display text-base font-extrabold"><span class="brand-mark"></span>{{ config('app.name') }}</div>
        <h1 class="mb-2 text-2xl">Set your password</h1>
        <p class="mb-6 text-sm text-ink-600">Enter the email on your account and we'll send you a link to set a new password.</p>
        <form method="POST" action="{{ route('portal.password.email') }}" class="space-y-4 text-sm">
            @csrf
            <label class="block">Email
                <input type="email" name="email" value="{{ old('email') }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Email me a link</button>
            <a href="{{ route('portal.login') }}" class="block text-center text-brand-600">Back to log in</a>
        </form>
    </div>
</x-portal-layout>
