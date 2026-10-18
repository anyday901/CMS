{{-- A 1 to 5 rating as stars. --}}
@props(['value'])
<span class="whitespace-nowrap text-amber-500"><span class="sr-only">{{ __(':value out of :max', ['value' => $value, 'max' => App\Models\TicketFeedback::MAX_RATING]) }}</span>@for ($i = 1; $i <= App\Models\TicketFeedback::MAX_RATING; $i++)<span @class(['text-ink-300' => $i > $value]) aria-hidden="true">★</span>@endfor</span>
