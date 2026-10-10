<x-admin-layout title="Edit tax rule">
    <h1 class="mb-6 text-2xl font-semibold">Edit tax rule</h1>
    <form method="POST" action="{{ route('admin.tax-rules.update', $rule) }}" class="max-w-md space-y-3 rounded-lg border border-gray-200 bg-white p-4 text-sm">
        @csrf @method('PUT')
        @include('admin.tax-rules.fields')
        @if ($errors->any())<p class="text-red-600">{{ $errors->first() }}</p>@endif
        <button class="rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">Save</button>
    </form>
</x-admin-layout>
