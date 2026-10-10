<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Product;
use App\Provisioning\ModuleRegistry;
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
        $products = Product::with('prices')->withCount('services')->orderBy('name')->get();

        return view('admin.products.index', compact('products'));
    }

    public function create(): View
    {
        return view('admin.products.form', ['product' => new Product, 'prices' => collect(), 'modules' => $this->modules->all()]);
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

        return view('admin.products.form', ['product' => $product, 'prices' => $prices, 'modules' => $this->modules->all()]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        DB::transaction(fn () => $this->save($request, $product));
        Activity::record("Updated product {$product->name}", $product);

        return redirect()->route('admin.products.index')->with('status', 'Product updated.');
    }

    private function save(Request $request, Product $product): void
    {
        $money = ['nullable', Money::rule()];
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'active' => ['boolean'],
            'taxable' => ['boolean'],
        ];
        foreach (BillingCycle::cases() as $cycle) {
            $rules["prices.{$cycle->value}.price"] = $money;
            $rules["prices.{$cycle->value}.setup_fee"] = $money;
        }
        $data = $request->validate($rules);

        $product->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'active' => $request->boolean('active'),
            'taxable' => $request->boolean('taxable'),
            ...$this->moduleSettings($request),
        ])->save();

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

    /** @return array{module: ?string, module_config: ?array<string, string>} */
    private function moduleSettings(Request $request): array
    {
        $request->validate(['module' => ['nullable', Rule::in($this->modules->all()->keys()->all())]]);
        $module = $this->modules->find($request->input('module') ?: null);

        if ($module === null) {
            return ['module' => null, 'module_config' => null];
        }

        $rules = collect($module->configFields())
            ->mapWithKeys(fn (array $field, string $name) => ["module_config.{$module->key()}.{$name}" => [($field['required'] ?? false) ? 'required' : 'nullable', 'string', 'max:1000']])
            ->all();
        $config = $request->validate($rules)['module_config'][$module->key()] ?? [];

        return ['module' => $module->key(), 'module_config' => array_filter($config, fn ($value) => $value !== null)];
    }
}
