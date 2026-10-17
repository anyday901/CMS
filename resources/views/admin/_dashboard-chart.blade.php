{{-- $overview (App\Support\SystemOverview): new and accepted orders on the left axis, income on the right. --}}
@php
    use App\Support\Money;

    $labels = $overview->labels();
    $series = [
        'new' => $overview->newOrders(),
        'activated' => $overview->activatedOrders(),
        'income' => $overview->income(),
    ];
    [$width, $height, $left, $right, $top, $bottom] = [800, 260, 40, 70, 10, 28];
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $count = count($labels);
    $nice = function (int $max): int {
        if ($max <= 4) {
            return 4;
        }
        $step = 10 ** (strlen((string) $max) - 1);

        return (int) (ceil($max / $step) * $step);
    };
    // A multiple of four, so each of the four gridlines falls on a whole order.
    $ordersMax = (int) ceil($nice(max([1, ...$series['new'], ...$series['activated']])) / 4) * 4;
    $incomeMax = $nice((int) ceil(max([0, ...$series['income']]) / 100)) * 100;
    $x = fn (int $i) => round($left + ($count > 1 ? $i * $plotWidth / ($count - 1) : $plotWidth / 2), 1);
    $y = fn (int $value, int $max) => round($top + $plotHeight - max(0, $value) / $max * $plotHeight, 1);
    $line = fn (array $values, int $max) => collect($values)->map(fn ($value, $i) => $x($i).','.$y($value, $max))->implode(' ');
    $area = fn (array $values, int $max) => $x(0).','.($top + $plotHeight).' '.$line($values, $max).' '.$x($count - 1).','.($top + $plotHeight);
    // Label at most about a dozen points so they don't run together.
    $every = max(1, (int) ceil($count / 12));
@endphp
<div class="mb-2 flex flex-wrap items-center justify-center gap-x-5 gap-y-1 text-xs text-ink-600">
    <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-5 rounded-sm bg-ink-200 ring-1 ring-ink-300"></span>New Orders</span>
    <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-5 rounded-sm bg-brand-600"></span>Activated Orders</span>
    <span class="flex items-center gap-1.5"><span class="inline-block h-2.5 w-5 rounded-sm bg-sun-300 ring-1 ring-sun-400"></span>Income</span>
</div>
<svg viewBox="0 0 {{ $width }} {{ $height }}" class="h-auto w-full" role="img" aria-label="New orders, activated orders and income, {{ strtolower(App\Support\SystemOverview::PERIODS[$overview->period]) }}">
    @foreach (range(0, 4) as $step)
        @php $lineY = $top + $plotHeight - $step * $plotHeight / 4; @endphp
        <line x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $lineY }}" y2="{{ $lineY }}" class="stroke-ink-100" stroke-width="1" />
        <text x="{{ $left - 6 }}" y="{{ $lineY + 4 }}" text-anchor="end" class="fill-ink-500 text-[11px]">{{ $ordersMax * $step / 4 }}</text>
        <text x="{{ $width - $right + 6 }}" y="{{ $lineY + 4 }}" class="fill-ink-500 text-[11px]">{{ Money::format((int) ($incomeMax * $step / 4)) }}</text>
    @endforeach
    <polygon points="{{ $area($series['income'], $incomeMax) }}" class="fill-sun-300/50 stroke-sun-400" stroke-width="1.5" stroke-linejoin="round" />
    <polygon points="{{ $area($series['new'], $ordersMax) }}" class="fill-ink-200/60 stroke-ink-400" stroke-width="1.5" stroke-linejoin="round" />
    <polyline points="{{ $line($series['activated'], $ordersMax) }}" fill="none" class="stroke-brand-600" stroke-width="2.5" stroke-linejoin="round" />
    @foreach ($labels as $i => $label)
        <g>
            <title>{{ $label }}: {{ $series['new'][$i] }} new, {{ $series['activated'][$i] }} activated, {{ Money::format($series['income'][$i]) }} income</title>
            <rect x="{{ $x($i) - $plotWidth / max(1, $count) / 2 }}" y="{{ $top }}" width="{{ $plotWidth / max(1, $count) }}" height="{{ $plotHeight }}" fill="transparent" />
        </g>
        @if ($i % $every === 0)
            <text x="{{ $x($i) }}" y="{{ $height - 8 }}" text-anchor="middle" class="fill-ink-500 text-[11px]">{{ $label }}</text>
        @endif
    @endforeach
</svg>
