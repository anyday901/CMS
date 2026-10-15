@php use App\Support\Money; @endphp
<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500">
            <tr><th class="px-4 py-2">{{ __('Service') }}</th><th class="px-4 py-2">{{ __('Price') }}</th><th class="px-4 py-2">{{ __('Next due') }}</th><th class="px-4 py-2">{{ __('Status') }}</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($services as $service)
                <tr>
                    <td class="px-4 py-2"><a href="{{ route('portal.services.show', $service) }}" class="text-brand-600">{{ $service->description() }}</a></td>
                    <td class="px-4 py-2">{{ Money::format($service->netRecurring(), auth('client')->user()->currency) }} {{ Str::lower(__($service->billing_cycle->label())) }}</td>
                    <td class="px-4 py-2">{{ $service->next_due_date?->translatedFormat('M j, Y') ?? '—' }}</td>
                    <td class="px-4 py-2"><x-status-badge :status="$service->status" /></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
