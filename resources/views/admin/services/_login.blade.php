{{-- The username and password of a service whose module doesn't use servers, such as one brought over from WHMCS. --}}
@php $hasLogin = filled($service->username) || filled($service->password); @endphp
@if ($hasLogin || auth()->user()->can('manage-clients'))
    <div class="mb-8 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        <h2 class="mb-2 font-semibold">Login</h2>
        @if ($hasLogin)
            <dl class="mb-3 grid gap-3 sm:grid-cols-4">
                <div><dt class="text-ink-500">Username</dt><dd>{{ $service->username ?: '—' }}</dd></div>
                <div><dt class="text-ink-500">Password</dt>
                    <dd>@if (blank($service->password))
                            —
                        @else
                            @can('manage-clients')
                                <details><summary class="cursor-pointer text-brand-600">Show</summary><code class="break-all">{{ $service->password }}</code></details>
                            @else
                                Set
                            @endcan
                        @endif</dd></div>
            </dl>
        @endif
        @can('manage-clients')
            <details @if ($errors->hasAny(['username', 'password'])) open @endif>
                <summary class="cursor-pointer text-brand-600">{{ $hasLogin ? 'Change the login' : 'Add a username and password' }}</summary>
                <form method="POST" action="{{ route('admin.services.login', $service) }}" class="mt-3 grid max-w-3xl gap-4 sm:grid-cols-2">
                    @csrf @method('PUT')
                    <label class="block">Username
                        <input name="username" value="{{ old('username', $service->username) }}" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        @error('username')<span class="text-red-700">{{ $message }}</span>@enderror
                    </label>
                    <label class="block">New password
                        <input type="password" name="password" autocomplete="new-password" placeholder="Leave blank to keep it" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        @error('password')<span class="text-red-700">{{ $message }}</span>@enderror
                    </label>
                    <p class="text-ink-500 sm:col-span-2">Kept for reference and shown to the client on their service. Nothing is changed anywhere else.</p>
                    <div class="sm:col-span-2"><button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button></div>
                </form>
            </details>
        @endcan
    </div>
@endif
