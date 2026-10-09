<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(): View
    {
        $products = Product::with('prices')->withCount('services')->orderBy('name')->get();

        return view('admin.products.index', compact('products'));
    }

    public function create(): View
    {
        return view('admin.products.form', ['product' => new Product, 'prices' => collect()]);
    }

    public function store(Request $request): RedirectResponse
    {
        DB::transaction(fn () => $this->save($request, new Product));

        return redirect()->route('admin.products.index')->with('status', 'Product created.');
    }

    public function edit(Product $product): View
    {
        $prices = $product->prices()->where('currency', config('billing.currency'))->get()
            ->keyBy(fn ($price) => $price->billing_cycle->value);

        return view('admin.products.form', compact('product', 'prices'));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        DB::transaction(fn () => $this->save($request, $product));

        return redirect()->route('admin.products.index')->with('status', 'Product updated.');
    }

    private function save(Request $request, Product $product): void
    {
        $money = ['nullable', 'regex:/^\$?[\d,]*(\.\d{1,2})?$/'];
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'active' => ['boolean'],
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
}
