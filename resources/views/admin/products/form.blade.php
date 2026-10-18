@php use App\Support\Money; @endphp
<x-admin-layout :title="$product->exists ? 'Edit product' : 'Add product'">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $product->exists ? 'Edit '.$product->name : 'Add product' }}</h1>
        @if ($product->exists)
            <a href="{{ route('admin.product-options.index', $product) }}" class="ml-auto rounded-full px-4 py-2 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Configurable options ({{ $product->options()->count() }})</a>
        @endif
    </div>
    <form method="POST" action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}"
          class="max-w-3xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @if ($product->exists) @method('PUT') @endif
        <label class="block">Name
            <input name="name" value="{{ old('name', $product->name) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">Group in the store
                <select name="product_group_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <option value="">None</option>
                    @foreach ($groups as $group)
                        <option value="{{ $group->id }}" @selected((string) old('product_group_id', $product->product_group_id) === (string) $group->id)>{{ $group->name }}</option>
                    @endforeach
                </select>
                <span class="text-ink-500"><a href="{{ route('admin.product-groups.index') }}" class="text-brand-600">Manage groups</a></span>
            </label>
            <label class="block">Sort order
                <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $product->sort_order ?? 0) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">Lower numbers come first in the store.</span>
            </label>
        </div>
        <label class="block">Description
            <textarea name="description" rows="3" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('description', $product->description) }}</textarea>
        </label>
        <label class="block">Welcome email text (optional)
            <textarea name="welcome_email" rows="4" placeholder="Log in to your control panel at https://panel.example.com with the details we sent." class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('welcome_email', $product->welcome_email) }}</textarea>
            <span class="text-ink-500">Added to the <a href="{{ route('admin.email-templates.edit', 'service_welcome') }}" class="text-brand-600">service welcome email</a> where it says {product_welcome}. Markdown works.</span>
        </label>
        <input type="hidden" name="active" value="0">
        <label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked(old('active', $product->exists ? $product->active : true))> Available for new orders</label>
        <input type="hidden" name="taxable" value="0">
        <label class="flex items-center gap-2"><input type="checkbox" name="taxable" value="1" @checked(old('taxable', $product->exists ? $product->taxable : true))> Taxable</label>
        <input type="hidden" name="is_addon" value="0">
        <label class="flex items-start gap-2"><input type="checkbox" name="is_addon" value="1" class="mt-0.5" @checked(old('is_addon', $product->is_addon))>
            <span>This is an add-on. Add-ons aren't listed in the store on their own; clients add them to the products chosen below on those products' forms, or to a service they already have.</span></label>

        @if ($addonProducts->isNotEmpty())
            <fieldset data-not-addon>
                <legend class="mb-1 font-semibold">Add-ons offered with this product</legend>
                <x-product-picker name="addons" :products="$addonProducts" :chosen="$chosenAddons" />
            </fieldset>
        @endif
        @if ($upgradeProducts->isNotEmpty())
            <fieldset data-not-addon>
                <legend class="mb-1 font-semibold">Clients can upgrade or downgrade to</legend>
                <p class="mb-2 text-ink-500">From the service page in the portal, on the same billing cycle. The rest of the period is prorated: a dearer product is invoiced, a cheaper one is added to the client's credit.</p>
                <x-product-picker name="upgrades" :products="$upgradeProducts" :chosen="$chosenUpgrades" />
            </fieldset>
        @endif
        <script>
            // Add-ons have no add-ons or upgrades of their own.
            (() => {
                const box = document.querySelector('input[type=checkbox][name=is_addon]');
                const sync = () => document.querySelectorAll('[data-not-addon]').forEach(el => { el.hidden = box.checked; });
                box.addEventListener('change', sync);
                sync();
            })();
        </script>

        <fieldset class="space-y-3">
            <legend class="mb-1 font-semibold">Billing</legend>
            <div class="grid gap-4 sm:grid-cols-3">
                <label class="block">Bill on day of the month
                    <input type="number" name="prorata_day" min="1" max="28" value="{{ old('prorata_day', $product->prorata_day) }}" placeholder="Order date" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <span class="text-ink-500">1 to 28. The first invoice is prorated up to this day, so every service renews on it.</span>
                </label>
                <label class="block">Also charge the next period from day
                    <input type="number" name="prorata_extend_day" min="1" max="31" value="{{ old('prorata_extend_day', $product->prorata_extend_day) }}" placeholder="Never" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <span class="text-ink-500">Orders on or after this day pay the short period and the next full one together.</span>
                </label>
                <label class="block">Free trial days
                    <input type="number" name="trial_days" min="1" max="365" value="{{ old('trial_days', $product->trial_days) }}" placeholder="No trial" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <span class="text-ink-500">Once per client, on recurring cycles only. The first invoice comes when the trial ends; any setup fee is charged up front.</span>
                </label>
            </div>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 font-semibold">Stock</legend>
            <input type="hidden" name="stock_control" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="stock_control" value="1" @checked(old('stock_control', $product->stock_control))> Count stock. Each order from the store takes one; the store shows it as out of stock at none.</label>
            <label class="block">In stock
                <input type="number" name="stock_quantity" min="0" value="{{ old('stock_quantity', $product->stock_quantity ?? 0) }}" class="mt-1 block w-28 rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">Cancelling an order or marking it as fraud puts its stock back.</span>
            </label>
        </fieldset>

        <div>
            <h2 class="mb-1 font-semibold">Pricing ({{ config('billing.currency') }})</h2>
            <p class="mb-3 text-ink-500">Leave a price blank to not offer that billing cycle.@if ($otherCurrencies) Clients billed in another currency see the prices set for it below; fill them in, or convert them all from {{ config('billing.currency') }} under <a href="{{ route('admin.currencies.index') }}" class="text-brand-600">Currencies</a>.@endif</p>
            <table class="text-sm">
                <thead class="text-left text-ink-500"><tr><th class="pr-4">Cycle</th><th class="pr-4">Price</th><th>Setup fee</th></tr></thead>
                <tbody>
                    @foreach (App\Enums\BillingCycle::cases() as $cycle)
                        @php $price = $prices[$cycle->value] ?? null; @endphp
                        <tr>
                            <td class="py-1 pr-4">{{ $cycle->label() }}</td>
                            <td class="py-1 pr-4"><label for="price-{{ $cycle->value }}" class="sr-only">{{ $cycle->label() }} price</label><input id="price-{{ $cycle->value }}" name="prices[{{ $cycle->value }}][price]" value="{{ old("prices.{$cycle->value}.price", $price ? Money::toInput($price->price) : '') }}" placeholder="0.00" class="w-28 rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                            <td class="py-1"><label for="setup-fee-{{ $cycle->value }}" class="sr-only">{{ $cycle->label() }} setup fee</label><input id="setup-fee-{{ $cycle->value }}" name="prices[{{ $cycle->value }}][setup_fee]" value="{{ old("prices.{$cycle->value}.setup_fee", $price && $price->setup_fee ? Money::toInput($price->setup_fee) : '') }}" placeholder="0.00" class="w-28 rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($otherCurrencies)
            <div>
                <h2 class="mb-1 font-semibold">Pricing in other currencies</h2>
                <p class="mb-3 text-ink-500">A billing cycle without a price in a currency isn't offered to clients billed in it.</p>
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead class="text-left text-ink-500">
                            <tr><th class="pr-4"></th>@foreach ($otherCurrencies as $code)<th colspan="2" class="pr-4">{{ $code }}</th>@endforeach</tr>
                            <tr><th class="pr-4">Cycle</th>@foreach ($otherCurrencies as $code)<th class="pr-2 font-normal">Price</th><th class="pr-4 font-normal">Setup fee</th>@endforeach</tr>
                        </thead>
                        <tbody>
                            @foreach (App\Enums\BillingCycle::cases() as $cycle)
                                <tr>
                                    <td class="py-1 pr-4">{{ $cycle->label() }}</td>
                                    @foreach ($otherCurrencies as $code)
                                        @php $price = $otherPrices->get($code)?->get($cycle->value); @endphp
                                        <td class="py-1 pr-2"><label for="price-{{ $code }}-{{ $cycle->value }}" class="sr-only">{{ $cycle->label() }} price in {{ $code }}</label><input id="price-{{ $code }}-{{ $cycle->value }}" name="currency_prices[{{ $code }}][{{ $cycle->value }}][price]" value="{{ old("currency_prices.{$code}.{$cycle->value}.price", $price ? Money::toInput($price->price) : '') }}" placeholder="0.00" class="w-24 rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                                        <td class="py-1 pr-4"><label for="setup-fee-{{ $code }}-{{ $cycle->value }}" class="sr-only">{{ $cycle->label() }} setup fee in {{ $code }}</label><input id="setup-fee-{{ $code }}-{{ $cycle->value }}" name="currency_prices[{{ $code }}][{{ $cycle->value }}][setup_fee]" value="{{ old("currency_prices.{$code}.{$cycle->value}.setup_fee", $price && $price->setup_fee ? Money::toInput($price->setup_fee) : '') }}" placeholder="0.00" class="w-24 rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($modules->isNotEmpty())
            <fieldset class="space-y-3">
                <legend class="mb-1 font-semibold">Provisioning</legend>
                <label class="block">Module
                    <select name="module" id="product-module" class="mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300">
                        <option value="">None (set up by hand)</option>
                        @foreach ($modules as $key => $module)
                            <option value="{{ $key }}" @selected(old('module', $product->module) === $key)>{{ $module->label() }}</option>
                        @endforeach
                    </select>
                </label>
                @php $serverModules = $modules->filter(fn ($module) => $module instanceof App\Provisioning\UsesServers); @endphp
                @if ($serverModules->isNotEmpty())
                    <label class="block" data-module="{{ $serverModules->keys()->join(' ') }}">Server group
                        <select name="server_group_id" class="mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300">
                            <option value="">None: the least used server of the module</option>
                            @foreach ($serverGroups as $serverGroup)
                                <option value="{{ $serverGroup->id }}" @selected((string) old('server_group_id', $product->server_group_id) === (string) $serverGroup->id)>{{ $serverGroup->name }} ({{ strtolower($serverGroup->fillLabel()) }}, {{ $serverGroup->servers_count }} {{ Str::plural('server', $serverGroup->servers_count) }})</option>
                            @endforeach
                        </select>
                        <span class="text-ink-500">Which servers new services go on. Set up servers and groups under <a href="{{ route('admin.servers.index') }}" class="text-brand-600">Servers</a>.</span>
                    </label>
                @endif
                @foreach ($modules as $key => $module)
                    @foreach ($module->configFields() as $name => $field)
                        <label class="block" data-module="{{ $key }}">{{ $module->label() }}: {{ $field['label'] }}@if ($field['required'] ?? false) <span class="text-ink-500">(required)</span>@endif
                            @php $value = old("module_config.{$key}.{$name}", $product->module === $key ? ($product->module_config[$name] ?? '') : ($field['default'] ?? '')); @endphp
                            @if (($field['type'] ?? 'text') === 'select')
                                <select name="module_config[{{ $key }}][{{ $name }}]" class="mt-1 block w-full max-w-md rounded-md px-3 py-2 ring-1 ring-ink-300">
                                    @foreach ($field['options'] ?? [] as $optionValue => $optionLabel)
                                        <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                                    @endforeach
                                </select>
                            @elseif (($field['type'] ?? 'text') === 'textarea')
                                <textarea name="module_config[{{ $key }}][{{ $name }}]" rows="4" class="mt-1 block w-full max-w-md rounded-md px-3 py-2 ring-1 ring-ink-300">{{ $value }}</textarea>
                            @else
                                <input name="module_config[{{ $key }}][{{ $name }}]" type="{{ in_array($field['type'] ?? 'text', ['url', 'number'], true) ? $field['type'] : 'text' }}" @if (($field['type'] ?? 'text') === 'number') min="0" @endif value="{{ $value }}" class="mt-1 block w-full max-w-md rounded-md px-3 py-2 ring-1 ring-ink-300">
                            @endif
                            @if ($field['help'] ?? null)<span class="text-ink-500">{{ $field['help'] }}</span>@endif
                        </label>
                    @endforeach
                @endforeach
                @php $usageModules = $modules->filter(fn ($module) => $module instanceof App\Provisioning\ReportsUsage); @endphp
                @if ($usageModules->isNotEmpty())
                    <div class="space-y-3 rounded-md bg-ink-50 p-3" data-module="{{ $usageModules->keys()->join(' ') }}">
                        <input type="hidden" name="overage_billing" value="0">
                        <label class="flex items-start gap-2"><input type="checkbox" name="overage_billing" value="1" class="mt-0.5" @checked(old('overage_billing', $product->overage_billing))>
                            <span><strong>Usage billing:</strong> after each month, invoice the disk and bandwidth a service used over what is included here. Use is read from the server every hour.</span></label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">Disk included (MB)
                                <input type="number" name="overage_disk_mb" min="0" value="{{ old('overage_disk_mb', $product->overage_disk_mb) }}" placeholder="Not billed" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                            </label>
                            <label class="block">Price per GB of disk over
                                <input name="overage_disk_price" value="{{ old('overage_disk_price', $product->overage_disk_price ? Money::toInput($product->overage_disk_price) : '') }}" placeholder="0.00" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                            </label>
                            <label class="block">Bandwidth included each month (MB)
                                <input type="number" name="overage_bandwidth_mb" min="0" value="{{ old('overage_bandwidth_mb', $product->overage_bandwidth_mb) }}" placeholder="Not billed" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                            </label>
                            <label class="block">Price per GB of bandwidth over
                                <input name="overage_bandwidth_price" value="{{ old('overage_bandwidth_price', $product->overage_bandwidth_price ? Money::toInput($product->overage_bandwidth_price) : '') }}" placeholder="0.00" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                            </label>
                        </div>
                        <p class="text-ink-500">Disk is billed on the month's last reading. Leave an allowance blank to not bill it. 1 GB is 1,024 MB. Prices are in {{ config('billing.currency') }}; clients paying in another currency aren't charged overages.</p>
                    </div>
                @endif
            </fieldset>
            <script>
                // Show only the settings of the chosen module. data-module can list several.
                (() => {
                    const select = document.getElementById('product-module');
                    const sync = () => document.querySelectorAll('[data-module]').forEach(el => { el.hidden = !el.dataset.module.split(' ').includes(select.value); });
                    select.addEventListener('change', sync);
                    sync();
                })();
            </script>
        @endif

        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
