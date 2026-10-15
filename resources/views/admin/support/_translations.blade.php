{{-- Translations of $model (a knowledge base article or announcement), $type being its translations route segment; $withTags adds a tags field. --}}
@php
    $languages = App\Support\Language::others();
    $saved = $model->translations->keyBy('locale');
@endphp
<section id="translations" class="mt-8 max-w-3xl">
    <h2 class="mb-1 text-lg font-semibold">Translations</h2>
    @if ($languages === [])
        <p class="text-sm text-ink-600">This is in {{ App\Support\Language::name(App\Support\Language::main()) }}. To translate it, add languages under @can('manage-settings')<a href="{{ route('admin.settings.edit') }}#languages" class="text-brand-600">Settings</a>@else Settings @endcan.</p>
    @else
        <p class="mb-3 text-sm text-ink-600">Visitors see the translation into their language, or the {{ App\Support\Language::name(App\Support\Language::main()) }} text above when there isn't one.@if ($saved->keys()->diff($languages)->isNotEmpty()) Translations into languages no longer offered under Settings are kept but not shown.@endif</p>
        <div class="space-y-2">
            @foreach ($languages as $code)
                @php
                    $translation = $saved->get($code);
                    $mine = old('locale') === $code;
                @endphp
                <details id="translation-{{ $code }}" class="rounded-lg border border-ink-200 bg-white text-sm" @if ($mine) open @endif>
                    <summary class="cursor-pointer px-4 py-3 font-medium">{{ App\Support\Language::name($code) }} <span class="font-normal text-ink-500">{{ $translation ? '· '.$translation->title : '· not translated yet' }}</span></summary>
                    <form method="POST" action="{{ route('admin.translations.update', [$type, $model->id, $code]) }}" lang="{{ $code }}" class="space-y-3 border-t border-ink-100 p-4">
                        @csrf @method('PUT')
                        <input type="hidden" name="locale" value="{{ $code }}">
                        <label class="block">Title
                            <input name="title" value="{{ $mine ? old('title') : $translation?->title }}" required maxlength="255" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                        </label>
                        <label class="block">Text (Markdown)
                            <textarea name="body" rows="10" required class="mt-1 w-full rounded-md px-3 py-2 font-mono text-xs ring-1 ring-ink-300">{{ $mine ? old('body') : $translation?->body }}</textarea>
                        </label>
                        @if ($withTags ?? false)
                            <label class="block">Tags (optional)
                                <input name="tags" value="{{ $mine ? old('tags') : $translation?->tags }}" maxlength="255" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                                <span class="text-ink-500">Comma separated, in {{ App\Support\Language::name($code) }}. Left blank, the main tags are used.</span>
                            </label>
                        @endif
                        <div class="flex flex-wrap gap-2">
                            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save {{ App\Support\Language::name($code) }}</button>
                        </div>
                    </form>
                    @if ($translation)
                        <form method="POST" action="{{ route('admin.translations.destroy', [$type, $model->id, $code]) }}" class="px-4 pb-4" onsubmit="return confirm('Remove this translation?')">
                            @csrf @method('DELETE')
                            <button class="rounded-full px-4 py-2 text-red-700 ring-1 ring-red-200 hover:bg-red-50">Remove translation</button>
                        </form>
                    @endif
                </details>
            @endforeach
        </div>
    @endif
</section>
