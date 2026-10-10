<x-admin-layout title="Staff">
    <div class="mb-4 flex items-center">
        <h1 class="text-2xl font-semibold">Staff</h1>
        <a href="{{ route('admin.staff.create') }}" class="ml-auto rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">Add staff member</a>
    </div>
    <div class="mb-6 overflow-x-auto rounded-lg border border-ink-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Email</th><th class="px-4 py-2">Role</th><th class="px-4 py-2"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($staff as $member)
                    <tr>
                        <td class="px-4 py-2"><a href="{{ route('admin.staff.edit', $member) }}" class="text-brand-600">{{ $member->name }}</a>@if ($member->is(auth()->user())) <span class="text-ink-500">(you)</span>@endif</td>
                        <td class="px-4 py-2">{{ $member->email }}</td>
                        <td class="px-4 py-2">{{ $member->role->label() }}</td>
                        <td class="px-4 py-2 text-right">
                            @unless ($member->is(auth()->user()))
                                <form method="POST" action="{{ route('admin.staff.destroy', $member) }}" onsubmit="return confirm('Remove {{ e($member->name) }}?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline">Remove</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="space-y-1 text-sm text-ink-600">
        @foreach (App\Enums\StaffRole::cases() as $role)
            <p><span class="font-medium text-ink-900">{{ $role->label() }}:</span> {{ $role->description() }}</p>
        @endforeach
    </div>
</x-admin-layout>
