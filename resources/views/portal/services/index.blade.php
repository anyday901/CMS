<x-portal-layout title="Services">
    <h1 class="mb-6 text-2xl font-semibold">Services</h1>
    @if ($services->isEmpty())
        <p class="text-sm text-gray-500">You have no services yet.</p>
    @else
        @include('portal.services._table')
    @endif
</x-portal-layout>
