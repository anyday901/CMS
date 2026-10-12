@php use App\Import\Whmcs\ImportWizard; @endphp
<x-admin-layout title="Import from WHMCS">
    <p class="mb-1 text-sm"><a href="{{ route('admin.settings.edit') }}" class="text-brand-600">Settings</a></p>
    <h1 class="mb-2 text-2xl font-semibold">Import from WHMCS</h1>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">Copy clients, contacts, products, services, invoices, payments, credit and tickets from WHMCS into this install. The walkthrough checks everything with a dry run first, so nothing is saved until the last step.</p>

    <ol class="mb-8 grid grid-cols-5 gap-2" aria-label="Import steps">
        @foreach (ImportWizard::STEPS as $number => $label)
            @php
                $done = $number < $step || $reachable === ImportWizard::FINISH;
                $current = $number === $step;
            @endphp
            <li class="relative flex flex-col items-center text-center" @if ($current) aria-current="step" @endif>
                @if (! $loop->first)
                    <span class="absolute right-1/2 top-5 -z-0 h-0.5 w-full {{ $number <= $reachable ? 'bg-brand-500' : 'bg-ink-200' }}" aria-hidden="true"></span>
                @endif
                @php
                    $circle = match (true) {
                        $current => 'bg-white text-brand-700 ring-4 ring-brand-500',
                        $done => 'bg-brand-600 text-white',
                        default => 'bg-white text-ink-400 ring-2 ring-ink-200',
                    };
                @endphp
                @if ($number <= $reachable && ! $current && $reachable !== ImportWizard::FINISH)
                    <a href="{{ route('admin.whmcs-import.show', ['step' => $number]) }}" class="relative z-10 flex h-10 w-10 items-center justify-center rounded-full font-display font-bold {{ $circle }}">{!! $done ? '&#10003;' : $number !!}</a>
                @else
                    <span class="relative z-10 flex h-10 w-10 items-center justify-center rounded-full font-display font-bold {{ $circle }}">{!! $done && ! $current ? '&#10003;' : $number !!}</span>
                @endif
                <span class="mt-2 text-xs sm:text-sm {{ $current ? 'font-semibold text-ink-900' : 'text-ink-500' }}">{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    <section class="max-w-3xl rounded-lg border border-ink-200 bg-white p-6 text-sm">
        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-brand-700">Step {{ $step }} of {{ count(ImportWizard::STEPS) }}</p>
        <h2 class="mb-4 font-display text-xl font-bold">{{ ImportWizard::STEPS[$step] }}</h2>
        @include('admin.whmcs-import.steps.'.[1 => 'prepare', 2 => 'connect', 3 => 'dry-run', 4 => 'import', 5 => 'finish'][$step])
    </section>

    @if ($step > ImportWizard::CONNECT && $step < ImportWizard::FINISH)
        <form method="POST" action="{{ route('admin.whmcs-import.reset') }}" class="mt-4">
            @csrf
            @method('DELETE')
            <button class="text-sm text-ink-500 underline hover:text-ink-900">Start again with a different WHMCS database</button>
        </form>
    @endif
</x-admin-layout>
