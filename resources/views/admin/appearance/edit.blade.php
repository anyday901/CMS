@php $input = 'mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300'; @endphp
<x-admin-layout title="Appearance">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Appearance</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">The portal's theme, and your own name, logo and colour across the portal, the admin area, emails and PDF invoices. Find more themes in the <a href="{{ route('admin.marketplace.index') }}" class="text-brand-600">marketplace</a>.</p>

    <form method="POST" action="{{ route('admin.appearance.update') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf @method('PUT')

        <fieldset class="rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <legend class="sr-only">Theme</legend>
            <h2 class="mb-3 text-base font-semibold">Portal theme</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($themes as $theme)
                    <label class="relative flex cursor-pointer flex-col gap-2 rounded-lg p-4 ring-1 has-[:checked]:ring-2 has-[:checked]:ring-brand-600 {{ $theme->key === $active->key ? 'ring-brand-600' : 'ring-ink-200' }}">
                        <span class="flex items-center gap-2">
                            <input type="radio" name="theme" value="{{ $theme->key }}" @checked(old('theme', $active->key) === $theme->key)>
                            <span class="font-semibold">{{ $theme->name }}</span>
                            @if ($theme->version)<span class="text-ink-500">{{ $theme->version }}</span>@endif
                        </span>
                        @if ($theme->screenshot())
                            <img src="{{ route('theme.asset', [$theme->key, $theme->screenshot()]) }}" alt="" class="aspect-video w-full rounded-md object-cover ring-1 ring-ink-200">
                        @else
                            <span class="flex h-20 overflow-hidden rounded-md ring-1 ring-ink-200" aria-hidden="true">
                                <span class="w-2/3" style="background: {{ $theme->colors['brand'] ?? '#0e7c6b' }}"></span>
                                <span class="w-1/3" style="background: {{ $theme->colors['ink'] ?? '#13322f' }}"></span>
                            </span>
                        @endif
                        <span class="text-ink-600">{{ $theme->description }}</span>
                        @if ($theme->author)<span class="text-xs text-ink-500">By {{ $theme->author }}</span>@endif
                    </label>
                @endforeach
            </div>
            <p class="mt-4 text-ink-500">Themes live in <code class="rounded bg-ink-100 px-1">{{ $themesPath }}</code>, one folder each. Change single pages without a theme of your own by copying a view from <code class="rounded bg-ink-100 px-1">resources/views</code> into <code class="rounded bg-ink-100 px-1">{{ $overridesPath }}</code> at the same path; overrides apply on top of any theme and survive updates. Admin pages can't be overridden. See the theme guide in the docs.</p>
            @if ($overridden->isNotEmpty())
                <p class="mt-2 text-ink-600">Overridden here: @foreach ($overridden as $file)<code class="rounded bg-ink-100 px-1">{{ $file }}</code>@if (! $loop->last), @endif @endforeach</p>
            @endif
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <legend class="sr-only">Branding</legend>
            <h2 class="text-base font-semibold">Branding</h2>
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <span class="block">Logo</span>
                    @if ($logo)
                        <img src="{{ $logo }}" alt="Current logo" class="mt-1 h-12 w-auto max-w-56 rounded bg-ink-50 object-contain p-1 ring-1 ring-ink-200">
                    @else
                        <span class="mt-1 block text-ink-500">None; the company name is shown instead.</span>
                    @endif
                </div>
                <label class="block">Upload a new logo
                    <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg" class="mt-1 block text-sm">
                    <span class="text-ink-500">PNG, JPG, WebP or SVG, up to 2 MB. About 48 pixels tall is shown. PDF invoices use PNG, JPG or WebP logos.</span>
                </label>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="flex items-center gap-2"><input type="hidden" name="use_color" value="0"><input type="checkbox" name="use_color" value="1" @checked(old('use_color', filled($color)))> Use my own brand colour</label>
                    <label class="mt-2 flex items-center gap-2">Colour
                        <input type="color" name="color" value="{{ old('color', $color ?: App\Http\Controllers\Admin\AppearanceController::themeColor($active)) }}" class="h-9 w-16 rounded-md ring-1 ring-ink-300">
                    </label>
                    <span class="mt-1 block text-ink-500">For buttons, links and highlights. Lighter and darker shades are worked out from it, and it replaces the theme's colour.</span>
                </div>
                <label class="block">Admin area name
                    <input name="admin_name" value="{{ old('admin_name', $adminName) }}" maxlength="100" placeholder="{{ $company['name'] ?? '' }}" class="{{ $input }}">
                    <span class="text-ink-500">Shown in the admin menu and browser tab. Blank uses the company name.</span>
                </label>
            </div>
            <label class="flex items-start gap-2"><input type="hidden" name="hide_powered_by" value="0"><input type="checkbox" name="hide_powered_by" value="1" class="mt-1" @checked(old('hide_powered_by', $hidePoweredBy))> <span>Hide "Powered by CMS" at the foot of the portal</span></label>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <legend class="sr-only">Company details</legend>
            <h2 class="text-base font-semibold">Company details</h2>
            <p class="text-ink-600">Printed on invoices and used in emails as {company_name}.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">Company name
                    <input name="company_name" value="{{ old('company_name', $company['name'] ?? '') }}" required maxlength="255" class="{{ $input }}">
                </label>
                <label class="block">Billing email
                    <input type="email" name="company_email" value="{{ old('company_email', $company['email'] ?? '') }}" maxlength="255" class="{{ $input }}">
                </label>
                <label class="block">Address
                    <textarea name="company_address" rows="3" maxlength="1000" class="{{ $input }}">{{ old('company_address', str_replace('\n', "\n", (string) ($company['address'] ?? ''))) }}</textarea>
                </label>
                <label class="block">Tax ID
                    <input name="company_tax_id" value="{{ old('company_tax_id', $company['tax_id'] ?? '') }}" maxlength="100" class="{{ $input }}">
                </label>
            </div>
        </fieldset>

        <button class="rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">Save appearance</button>
    </form>

    @if ($logo)
        <form method="POST" action="{{ route('admin.appearance.logo.destroy') }}" class="mt-3 text-sm">
            @csrf @method('DELETE')
            <button class="text-red-700">Remove the logo</button>
        </form>
    @endif
</x-admin-layout>
