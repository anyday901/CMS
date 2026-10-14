{{-- A 1 to 5 rating as stars. --}}
@props(['value'])
<span class="whitespace-nowrap text-amber-500" role="img" aria-label="{{ $value }} out of {{ App\Models\TicketFeedback::MAX_RATING }}">@for ($i = 1; $i <= App\Models\TicketFeedback::MAX_RATING; $i++)<span @class(['text-ink-300' => $i > $value]) aria-hidden="true">★</span>@endfor</span>
