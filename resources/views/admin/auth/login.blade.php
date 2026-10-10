<x-admin-layout title="Log in">
    <div class="mx-auto mt-16 max-w-sm rounded-3xl bg-white p-7 shadow-[0_10px_30px_-12px_rgb(14_124_107/0.35)]">
        <div class="mb-4 flex items-center gap-2 font-display text-base font-extrabold"><span class="brand-mark"></span>{{ config('app.name') }}</div>
        <h1 class="mb-6 text-2xl">Staff log in</h1>
        <form method="POST" action="{{ route('admin.login') }}" class="space-y-4">
            @csrf
            <label class="block text-sm">Email
                <input type="email" name="email" value="{{ old('email') }}" required class="mt-1 w-full rounded-md border-ink-300 px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block text-sm">Password
                <input type="password" name="password" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1"> Remember me</label>
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">Log in</button>
        </form>
    </div>
</x-admin-layout>
