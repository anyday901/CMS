{{-- Cancellation request form, shared by the portal and admin service pages. --}}
<form method="POST" action="{{ $action }}" class="mt-3 max-w-xl space-y-3 rounded-lg border border-ink-200 bg-white p-4" onsubmit="return confirm('Cancel this service?')">
    @csrf
    @error('cancellation')<p class="rounded-md bg-red-50 px-3 py-2 text-red-800">{{ $message }}</p>@enderror
    <fieldset class="space-y-2">
        <legend class="mb-1 text-ink-500">When</legend>
        @if ($service->billing_cycle->isRecurring() && $periodEnd)
            <label class="flex items-start gap-2"><input type="radio" name="type" value="end_of_period" class="mt-1" checked>
                <span>At the end of the billing period, on {{ $periodEnd->max(today())->format('M j, Y') }}. Nothing more is invoiced.</span></label>
        @endif
        <label class="flex items-start gap-2"><input type="radio" name="type" value="immediate" class="mt-1" @checked(! $service->billing_cycle->isRecurring() || ! $periodEnd)>
            <span>Straight away. Time already paid for isn't refunded.</span></label>
    </fieldset>
    <label class="block">Reason <span class="text-ink-500">(optional)</span>
        <textarea name="reason" rows="3" maxlength="2000" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('reason') }}</textarea>
    </label>
    <button class="rounded-full px-4 py-2 font-medium text-red-700 ring-1 ring-red-300 hover:bg-red-50">Request cancellation</button>
</form>
