<x-admin-layout title="Edit promotion code">
    <h1 class="mb-2 text-2xl font-semibold">Edit {{ $promotion->code }}</h1>
    <p class="mb-6 text-sm text-ink-600">Used {{ $promotion->uses }} {{ Str::plural('time', $promotion->uses) }}. Changes apply to new orders; services already using the code keep the discount they were given.</p>
    <form method="POST" action="{{ route('admin.promotions.update', $promotion) }}" class="max-w-md space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf @method('PUT')
        @include('admin.promotions.fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
