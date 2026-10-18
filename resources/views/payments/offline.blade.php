<div class="rounded-md bg-ink-50 p-3">
    <h3 class="mb-1 font-medium">{{ __($label) }}</h3>
    <div class="text-ink-700">{!! nl2br(e($instructions)) !!}</div>
    <p class="mt-2 text-ink-600">{{ __('Include invoice #:number as the reference so we can match your payment.', ['number' => $invoice->number]) }}</p>
</div>
