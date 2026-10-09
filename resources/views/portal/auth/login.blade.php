<x-portal-layout title="Log in">
    <div class="mx-auto mt-16 max-w-sm rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h1 class="mb-6 text-xl font-semibold">Log in to your account</h1>
        <form method="POST" action="{{ route('portal.login') }}" class="space-y-4 text-sm">
            @csrf
            <label class="block">Email
                <input type="email" name="email" value="{{ old('email') }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
            </label>
            <label class="block">Password
                <input type="password" name="password" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
            </label>
            <label class="flex items-center gap-2"><input type="checkbox" name="remember" value="1"> Remember me</label>
            <button class="w-full rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">Log in</button>
            <a href="{{ route('portal.password.request') }}" class="block text-center text-indigo-600">Forgot or never set your password?</a>
        </form>
    </div>
</x-portal-layout>
