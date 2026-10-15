<x-portal-layout title="Ticket received" :store="true">
    @include('help._nav')
    <div class="max-w-2xl rounded-lg border border-ink-200 bg-white p-6 text-sm">
        <h1 class="mb-2 text-2xl font-semibold">Thanks, we've got your message</h1>
        <p class="text-ink-700">We've emailed <strong>{{ $email }}</strong> a link to your ticket. Use it to follow our replies and answer them, or simply reply to the email.</p>
        <p class="mt-3 text-ink-600">Nothing arrived after a few minutes? Check your spam folder.</p>
    </div>
</x-portal-layout>
