<x-admin-layout :title="$user->exists ? 'Edit staff member' : 'Add staff member'">
    <h1 class="mb-6 text-2xl font-semibold">{{ $user->exists ? 'Edit '.$user->name : 'Add staff member' }}</h1>
    <form method="POST" action="{{ $user->exists ? route('admin.staff.update', $user) : route('admin.staff.store') }}"
          class="max-w-xl space-y-4 rounded-lg border border-gray-200 bg-white p-6 text-sm">
        @csrf
        @if ($user->exists) @method('PUT') @endif
        <label class="block">Name
            <input name="name" value="{{ old('name', $user->name) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
        </label>
        <label class="block">Email
            <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
        </label>
        <fieldset>
            <legend class="mb-1">Role</legend>
            @foreach (App\Enums\StaffRole::cases() as $role)
                <label class="mb-1 flex items-start gap-2">
                    <input type="radio" name="role" value="{{ $role->value }}" class="mt-1" @checked(old('role', $user->exists ? $user->role->value : 'support') === $role->value)>
                    <span><span class="font-medium">{{ $role->label() }}</span> <span class="text-gray-500">{{ $role->description() }}</span></span>
                </label>
            @endforeach
        </fieldset>
        <label class="block">{{ $user->exists ? 'New password (leave blank to keep the current one)' : 'Password' }}
            <input type="password" name="password" autocomplete="new-password" @required(! $user->exists) minlength="12" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
        </label>
        <label class="block">Confirm password
            <input type="password" name="password_confirmation" autocomplete="new-password" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
        </label>
        <button class="rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">Save</button>
    </form>
</x-admin-layout>
