<x-admin-layout title="Portal text">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Portal text and translations</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">The portal, store and help pages come in English, German, French and Spanish. Change any wording here, in any language: your version wins over the one CMS ships and survives updates. To offer a language, add its code under <a href="{{ route('admin.settings.edit') }}#languages" class="text-brand-600">Settings › Languages</a>; you can translate one CMS doesn't ship here, and anything left untranslated shows in English. Emails are translated under <a href="{{ route('admin.email-templates.index') }}" class="text-brand-600">Email templates</a>.</p>

    <div class="overflow-hidden rounded-lg border border-ink-200 bg-white text-sm">
        <table class="w-full">
            <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2 font-normal">Language</th><th class="px-4 py-2 font-normal">Offered to clients</th><th class="px-4 py-2 font-normal">Translated by CMS</th><th class="px-4 py-2 font-normal">Your changes</th><th class="px-4 py-2"><span class="sr-only">Edit</span></th></tr></thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($languages as $language)
                    <tr>
                        <td class="px-4 py-2 font-medium">{{ $language['name'] }} <span class="font-mono text-xs text-ink-500">{{ $language['code'] }}</span></td>
                        <td class="px-4 py-2">{{ $language['offered'] ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">{{ $language['translated'] }} of {{ $total }}</td>
                        <td class="px-4 py-2">{{ $language['overrides'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-right"><a href="{{ route('admin.languages.edit', $language['code']) }}" class="text-brand-600">Edit text</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-admin-layout>
