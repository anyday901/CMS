@php
    $styles = [
        'ok' => ['bg-green-50 text-green-800', 'OK'],
        'warning' => ['bg-sun-300/40 text-amber-900', 'Check'],
        'problem' => ['bg-red-50 text-red-800', 'Problem'],
    ];
@endphp
<x-admin-layout title="System health">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">System health</h1>
        <span class="rounded-full px-3 py-1 text-sm font-medium {{ $styles[$overall][0] }}">{{ ['ok' => 'All good', 'warning' => 'Some things to check', 'problem' => 'Needs attention'][$overall] }}</span>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    @include('admin.system._nav')

    <div class="grid gap-6 md:grid-cols-3">
        <section class="rounded-lg border border-ink-200 bg-white text-sm md:col-span-2">
            <ul class="divide-y divide-ink-100">
                @foreach ($checks as $check)
                    <li class="flex items-start gap-4 px-6 py-3">
                        <span class="mt-0.5 w-20 shrink-0 rounded-full px-2 py-0.5 text-center text-xs font-medium {{ $styles[$check['status']][0] }}">{{ $styles[$check['status']][1] }}</span>
                        <div>
                            <p class="font-medium">{{ $check['label'] }}</p>
                            <p class="text-ink-600">{{ $check['detail'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <aside class="space-y-4 self-start text-sm">
            <div class="rounded-lg border border-ink-200 bg-white p-6">
                <h2 class="mb-2 text-base font-semibold">This install</h2>
                <dl class="grid grid-cols-2 gap-y-1">
                    <dt class="text-ink-500">CMS</dt><dd>{{ $version }}</dd>
                    <dt class="text-ink-500">PHP</dt><dd>{{ $php }}</dd>
                    <dt class="text-ink-500">Laravel</dt><dd>{{ $laravel }}</dd>
                    <dt class="text-ink-500">Environment</dt><dd>{{ app()->environment() }}</dd>
                    <dt class="text-ink-500">Timezone</dt><dd>{{ config('app.timezone') }}</dd>
                </dl>
            </div>
            <div class="rounded-lg border border-ink-200 bg-white p-6">
                <h2 class="mb-2 text-base font-semibold">Uptime monitoring</h2>
                <p class="text-ink-600">Point an uptime monitor at <code class="rounded bg-ink-100 px-1">{{ url('health') }}</code>. It answers 200 while nothing above is a problem, and 503 when something is. Set <code class="rounded bg-ink-100 px-1">HEALTH_TOKEN</code> in .env and add <code class="rounded bg-ink-100 px-1">?token=…</code> to see each check.</p>
            </div>
        </aside>
    </div>
</x-admin-layout>
