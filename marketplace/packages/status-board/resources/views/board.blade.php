<x-admin-layout :title="$title">
    <h1 class="mb-6 text-2xl font-semibold">{{ $title }}</h1>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            __('Received today') => $received,
            __('New orders today') => $orders,
            __('Orders waiting for review') => $pending,
            __('Tickets waiting for a reply') => $tickets,
        ] as $label => $value)
            <div class="rounded-lg border border-ink-200 bg-white p-6">
                <p class="text-sm text-ink-600">{{ $label }}</p>
                <p class="mt-2 font-display text-4xl font-extrabold">{{ $value }}</p>
            </div>
        @endforeach
    </div>
    <script>setTimeout(() => window.location.reload(), 60000);</script>
</x-admin-layout>
