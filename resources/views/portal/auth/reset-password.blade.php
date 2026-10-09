<x-portal-layout title="Choose a password">
    <div class="mx-auto mt-16 max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h1 class="mb-6 text-xl font-semibold">Choose a password</h1>
        <form method="POST" action="{{ route('portal.password.update') }}" class="space-y-4 text-sm">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label class="block">Email
                <input type="email" name="email" value="{{ old('email', $email) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
            </label>
            <label class="block">New password (at least 10 characters)
                <input type="password" name="password" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
            </label>
            <label class="block">Confirm new password
                <input type="password" name="password_confirmation" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
            </label>
            <button class="w-full rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">Save password</button>
        </form>
    </div>
</x-portal-layout>
