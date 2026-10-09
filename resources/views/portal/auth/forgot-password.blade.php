<x-portal-layout title="Set your password">
    <div class="mx-auto mt-16 max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h1 class="mb-2 text-xl font-semibold">Set your password</h1>
        <p class="mb-6 text-sm text-gray-600">Enter the email on your account and we'll send you a link to set a new password.</p>
        <form method="POST" action="{{ route('portal.password.email') }}" class="space-y-4 text-sm">
            @csrf
            <label class="block">Email
                <input type="email" name="email" value="{{ old('email') }}" required autofocus class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
            </label>
            <button class="w-full rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">Email me a link</button>
            <a href="{{ route('portal.login') }}" class="block text-center text-indigo-600">Back to log in</a>
        </form>
    </div>
</x-portal-layout>
