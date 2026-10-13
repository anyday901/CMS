<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ServerGroup;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\UsesServers;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private ModuleRegistry $modules) {}

    public function index(): View
    {
        $products = Product::with(['prices', 'group'])->withCount('services')->orderBy('is_addon')->orderBy('name')->get();

        return view('admin.products.index', compact('products'));
    }

    public function create(): View
    {
        return view('admin.products.form', ['product' => new Product, 'prices' => collect()] + $this->formData(new Product));
    }

    public function store(Request $request): RedirectResponse
    {
        $product = new Product;
        DB::transaction(fn () => $this->save($request, $product));
        Activity::record("Created product {$product->name}", $product);

        return redirect()->route('admin.products.index')->with('status', 'Product created.');
    }

    public function edit(Product $product): View
    {
        $prices = $product->prices()->where('currency', config('billing.currency'))->get()
            ->keyBy(fn ($price) => $price->billing_cycle->value);

        return view('admin.products.form', ['product' => $product, 'prices' => $prices] + $this->formData($product));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        DB::transaction(fn () => $this->save($request, $product));
        Activity::record("Updated product {$product->name}", $product);

        return redirect()->route('admin.products.index')->with('status', 'Product updated.');
    }

    /** @return array<string, mixed> */
    private function formData(Product $product): array
    {
        $others = Product::with('group')->whereKeyNot($product->id ?? 0)->orderBy('name')->get();

        return [
            'modules' => $this->modules->all(),
            'serverGroups' => ServerGroup::withCount('servers')->orderBy('name')->get(),
            'groups' => ProductGroup::ordered()->get(),
            'addonProducts' => $others->where('is_addon', true),
            'upgradeProducts' => $others->where('is_addon', false),
            'chosenAddons' => $product->exists ? $product->addons()->pluck('products.id')->all() : [],
            'chosenUpgrades' => $product->exists ? $product->upgrades()->pluck('products.id')->all() : [],
        ];
    }

    private function save(Request $request, Product $product): void
    {
        $money = ['nullable', Money::rule()];
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'welcome_email' => ['nullable', 'string', 'max:20000'],
            'active' => ['boolean'],
            'taxable' => ['boolean'],
            'is_addon' => ['boolean'],
            'product_group_id' => ['nullable', Rule::exists('product_groups', 'id')],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'addons' => ['array'],
            'addons.*' => [Rule::exists('products', 'id')->where('is_addon', true)],
            'upgrades' => ['array'],
            'upgrades.*' => [Rule::exists('products', 'id')->where('is_addon', false)],
            'prorata_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'prorata_extend_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'stock_control' => ['boolean'],
            'stock_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
        foreach (BillingCycle::cases() as $cycle) {
            $rules["prices.{$cycle->value}.price"] = $money;
            $rules["prices.{$cycle->value}.setup_fee"] = $money;
        }
        $data = $request->validate($rules);

        $product->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'welcome_email' => $data['welcome_email'] ?? null,
            'active' => $request->boolean('active'),
            'taxable' => $request->boolean('taxable'),
            'is_addon' => $request->boolean('is_addon'),
            'product_group_id' => $data['product_group_id'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'prorata_day' => $data['prorata_day'] ?? null,
            // Only meaningful with a billing day.
            'prorata_extend_day' => filled($data['prorata_day'] ?? null) ? ($data['prorata_extend_day'] ?? null) : null,
            'trial_days' => $data['trial_days'] ?? null,
            'stock_control' => $request->boolean('stock_control'),
            'stock_quantity' => (int) ($data['stock_quantity'] ?? 0),
            ...$this->moduleSettings($request),
        ])->save();

        // Add-ons don't have add-ons or upgrades of their own.
        $product->addons()->sync($product->is_addon ? [] : array_map('intval', $data['addons'] ?? []));
        $product->upgrades()->sync($product->is_addon ? [] : array_diff(array_map('intval', $data['upgrades'] ?? []), [$product->id]));

        $currency = config('billing.currency');
        foreach (BillingCycle::cases() as $cycle) {
            $input = $data['prices'][$cycle->value] ?? [];
            $price = trim((string) ($input['price'] ?? ''));

            if ($price === '') {
                $product->prices()->where('billing_cycle', $cycle)->where('currency', $currency)->delete();

                continue;
            }

            $product->prices()->updateOrCreate(
                ['billing_cycle' => $cycle, 'currency' => $currency],
                [
                    'price' => Money::parse($price),
                    'setup_fee' => Money::parse(trim((string) ($input['setup_fee'] ?? '')) ?: '0'),
                ],
            );
        }
    }

    /** @return array{module: ?string, module_config: ?array<string, string>, server_group_id: ?int} */
    private function moduleSettings(Request $request): array
    {
        $request->validate([
            'module' => ['nullable', Rule::in($this->modules->all()->keys()->all())],
            'server_group_id' => ['nullable', Rule::exists('server_groups', 'id')],
        ]);
        $module = $this->modules->find($request->input('module') ?: null);

        if ($module === null) {
            return ['module' => null, 'module_config' => null, 'server_group_id' => null];
        }

        $rules = collect($module->configFields())
            ->mapWithKeys(fn (array $field, string $name) => ["module_config.{$module->key()}.{$name}" => array_merge(
                [($field['required'] ?? false) ? 'required' : 'nullable', 'string'],
                match ($field['type'] ?? 'text') {
                    'textarea' => ['max:5000'],
                    'url' => ['max:1000', 'url:http,https'],
                    'number' => ['integer', 'min:0', 'max:1000000000'],
                    default => ['max:1000'],
                },
            )])
            ->all();
        $config = $request->validate($rules)['module_config'][$module->key()] ?? [];

        return [
            'module' => $module->key(),
            'module_config' => array_filter($config, fn ($value) => $value !== null),
            'server_group_id' => $module instanceof UsesServers && $request->filled('server_group_id') ? (int) $request->input('server_group_id') : null,
        ];
    }
}
