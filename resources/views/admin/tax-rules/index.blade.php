<x-admin-layout title="Tax rules">
    <h1 class="mb-2 text-2xl font-semibold">Tax rules</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">New invoices use the most specific rule that matches the client's address: country and state first, then country, then a rule with neither. Tax applies to taxable products only, and tax-exempt clients are never taxed. Changing a rule doesn't change invoices already created.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($rules->isEmpty())
                <p class="text-sm text-ink-500">No tax rules, so invoices have no tax.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Rate</th><th class="px-4 py-2">Applies to</th><th class="px-4 py-2"></th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($rules as $taxRule)
                                <tr>
                                    <td class="px-4 py-2"><a href="{{ route('admin.tax-rules.edit', $taxRule) }}" class="text-brand-600">{{ $taxRule->name }}</a></td>
                                    <td class="px-4 py-2">{{ $taxRule->rateLabel() }}</td>
                                    <td class="px-4 py-2">{{ collect([$taxRule->state, $taxRule->country])->filter()->join(', ') ?: 'Everywhere' }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <form method="POST" action="{{ route('admin.tax-rules.destroy', $taxRule) }}" onsubmit="return confirm('Delete this tax rule?')">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.tax-rules.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a tax rule</h2>
            @include('admin.tax-rules.fields')
            @if ($errors->any())<p class="text-red-600">{{ $errors->first() }}</p>@endif
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add rule</button>
        </form>
    </div>
</x-admin-layout>
