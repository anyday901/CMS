@php
    $input = 'mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300';
    $filters = old('filters', $mail->filters ?? []);
    $marketing = (bool) old('marketing', $mail->marketing);
@endphp
<x-admin-layout title="New mass email">
    <p class="mb-2 text-sm"><a href="{{ route('admin.mass-mail.index') }}" class="text-brand-600">Mass mail</a></p>
    <h1 class="mb-2 text-2xl font-semibold">New mass email</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Write one email and send it to the clients your filters pick. Preview it first to see how many clients it reaches and how it reads.</p>

    <form method="POST" action="{{ route('admin.mass-mail.preview') }}" class="grid gap-6 md:grid-cols-3">
        @csrf
        <div class="space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm md:col-span-2">
            <fieldset class="space-y-2">
                <legend class="mb-1 font-semibold">Kind of email</legend>
                <label class="flex items-start gap-2">
                    <input type="radio" name="marketing" value="1" class="mt-1" @checked($marketing)>
                    <span><strong>Marketing</strong>: news, offers and newsletters. Only goes to clients who agreed to marketing emails, with their unsubscribe link at the bottom and a one-click unsubscribe header for mail apps.</span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="radio" name="marketing" value="0" class="mt-1" @checked(! $marketing)>
                    <span><strong>Service notice</strong>: something clients need to know about their account or services, such as planned maintenance or a price change. Goes to everyone picked, whatever their marketing choice. Don't use it for promotions.</span>
                </label>
            </fieldset>
            <label class="block">Subject
                <input name="subject" value="{{ old('subject', $mail->subject) }}" required maxlength="250" class="{{ $input }}">
            </label>
            <label class="block">Message
                <textarea name="body" rows="14" required class="{{ $input }} font-mono">{{ old('body', $mail->body) }}</textarea>
                <span class="mt-1 block text-ink-500">Markdown, such as <code>**bold**</code> and <code>[a link](https://…)</code>. Placeholders: @foreach (App\Mail\MassMailer::PLACEHOLDERS as $placeholder)<code class="rounded bg-ink-100 px-1">{{ '{'.$placeholder.'}' }}</code> @endforeach</span>
            </label>
        </div>

        <div class="h-fit space-y-4 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            <h2 class="font-semibold">Send to clients who are</h2>
            <fieldset class="space-y-1">
                <legend class="sr-only">Client status</legend>
                @foreach ($clientStatuses as $status)
                    <label class="mr-3 inline-flex items-center gap-2"><input type="checkbox" name="filters[statuses][]" value="{{ $status->value }}" @checked(in_array($status->value, $filters['statuses'] ?? [], true))> {{ ucfirst($status->value) }}</label>
                @endforeach
            </fieldset>
            <label class="block">In the group
                <select name="filters[group]" class="{{ $input }}">
                    <option value="">Any group</option>
                    <option value="{{ App\Mail\MassMailAudience::NO_GROUP }}" @selected(($filters['group'] ?? '') === App\Mail\MassMailAudience::NO_GROUP)>No group</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}" @selected((string) ($filters['group'] ?? '') === (string) $group->id)>{{ $group->name }}</option>
                    @endforeach
                </select>
            </label>
            <div class="grid grid-cols-2 gap-3">
                <label class="block">Country
                    <select name="filters[country]" class="{{ $input }}">
                        <option value="">Any</option>
                        @foreach ($countries as $country)
                            <option value="{{ $country }}" @selected(($filters['country'] ?? '') === $country)>{{ $country }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">Currency
                    <select name="filters[currency]" class="{{ $input }}">
                        <option value="">Any</option>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <label class="block">With any of these products
                <select name="filters[product_ids][]" multiple size="5" class="{{ $input }}">
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected(in_array($product->id, array_map('intval', $filters['product_ids'] ?? []), true))>{{ $product->name }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-ink-500">None picked means any client. Hold Ctrl or ⌘ to pick several.</span>
            </label>
            <fieldset class="space-y-1">
                <legend>Where the service is</legend>
                @foreach ($serviceStatuses as $status)
                    <label class="mr-3 inline-flex items-center gap-2"><input type="checkbox" name="filters[service_statuses][]" value="{{ $status->value }}" @checked(in_array($status->value, $filters['service_statuses'] ?? ['active'], true))> {{ ucfirst($status->value) }}</label>
                @endforeach
            </fieldset>
            <button class="w-full rounded-full px-4 py-2 font-medium text-ink-700 ring-1 ring-ink-300 hover:bg-ink-50">Preview</button>
        </div>
    </form>

    @if ($preview)
        <section class="mt-8 space-y-4" aria-labelledby="preview-heading">
            <h2 id="preview-heading" class="text-lg font-semibold">Preview</h2>
            @if ($preview['count'] === 0)
                <p class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">No clients match these filters{{ $mail->marketing ? ' and agreed to marketing emails' : '' }}.</p>
            @else
                <p class="text-sm">This goes to <strong>{{ number_format($preview['count']) }} {{ Str::plural('client', $preview['count']) }}</strong>{{ $mail->marketing ? ' who agreed to marketing emails' : '' }}. Here's how it reads for {{ $preview['sample']['client']->fullName() }}:</p>
                <div class="rounded-lg border border-ink-200 bg-white text-sm">
                    <p class="border-b border-ink-100 px-4 py-2"><span class="text-ink-500">Subject:</span> {{ $preview['sample']['subject'] }}</p>
                    <div class="prose-content px-4 py-3">{!! App\Mail\EmailTemplates::html($preview['sample']['body']) !!}</div>
                    @if ($preview['sample']['unsubscribe'])
                        <p class="border-t border-ink-100 px-4 py-2 text-xs text-ink-500">Headers: List-Unsubscribe and List-Unsubscribe-Post (one-click unsubscribe).</p>
                    @endif
                </div>
                <form method="POST" action="{{ route('admin.mass-mail.store') }}" onsubmit="return confirm('Send this email to {{ $preview['count'] }} clients now?')">
                    @csrf
                    <input type="hidden" name="subject" value="{{ $mail->subject }}">
                    <input type="hidden" name="body" value="{{ $mail->body }}">
                    <input type="hidden" name="marketing" value="{{ $mail->marketing ? 1 : 0 }}">
                    @foreach ($mail->filters as $key => $value)
                        @foreach ((array) $value as $item)
                            <input type="hidden" name="filters[{{ $key }}]{{ is_array($value) ? '[]' : '' }}" value="{{ $item }}">
                        @endforeach
                    @endforeach
                    <button class="rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">Send to {{ number_format($preview['count']) }} {{ Str::plural('client', $preview['count']) }}</button>
                </form>
            @endif
        </section>
    @endif
</x-admin-layout>
