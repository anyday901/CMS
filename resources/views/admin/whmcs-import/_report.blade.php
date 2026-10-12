{{-- What a dry run or import found: $summary from ImportWizard::summary(), $run is dry_run or import. --}}
@if ($summary['mismatches'] === 0)
    <p class="mb-4 rounded-md bg-green-50 px-4 py-3 text-green-800">Every total matches WHMCS.</p>
@else
    <p class="mb-4 rounded-md bg-amber-50 px-4 py-3 text-amber-900">{{ $summary['mismatches'] }} {{ Str::plural('total', $summary['mismatches']) }} differ from WHMCS. The notes below and the full report list the skipped records behind them.</p>
@endif

<div class="mb-4 overflow-x-auto rounded-lg border border-ink-200">
    <table class="min-w-full">
        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-3 py-2">Records</th><th class="px-3 py-2 text-right">In WHMCS</th><th class="px-3 py-2 text-right">Imported</th><th class="px-3 py-2 text-right">Skipped</th></tr></thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($summary['counts'] as $section => $count)
                <tr>
                    <td class="px-3 py-2">{{ $section }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($count['found']) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($count['imported']) }}</td>
                    <td class="px-3 py-2 text-right {{ $count['skipped'] ? 'font-semibold text-amber-700' : '' }}">{{ number_format($count['skipped']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if ($summary['checks'] !== [])
    <div class="mb-4 overflow-x-auto rounded-lg border border-ink-200">
        <table class="min-w-full">
            <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-3 py-2">Total</th><th class="px-3 py-2 text-right">WHMCS</th><th class="px-3 py-2 text-right">Here</th><th class="px-3 py-2">Match</th></tr></thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($summary['checks'] as $check)
                    <tr>
                        <td class="px-3 py-2">{{ $check['label'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $check['whmcs'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $check['here'] }}</td>
                        <td class="px-3 py-2">{!! $check['whmcs'] === $check['here'] ? '<span class="text-green-700">&#10003; Yes</span>' : '<span class="font-semibold text-red-700">No</span>' !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@foreach ($summary['notes'] as $section => $notes)
    <details class="mb-2 rounded-md bg-ink-50 px-4 py-2">
        <summary class="cursor-pointer font-medium">{{ $section }}: {{ number_format($notes['total']) }} {{ Str::plural('note', $notes['total']) }}</summary>
        <ul class="mt-2 list-inside list-disc text-ink-600">
            @foreach ($notes['shown'] as $note)
                <li>{{ $note }}</li>
            @endforeach
        </ul>
        @if ($notes['total'] > count($notes['shown']))
            <p class="mt-1 text-ink-500">And {{ number_format($notes['total'] - count($notes['shown'])) }} more in the full report.</p>
        @endif
    </details>
@endforeach

<a href="{{ route('admin.whmcs-import.report', $run) }}" class="mt-2 inline-block text-brand-600">Download the full report</a>
