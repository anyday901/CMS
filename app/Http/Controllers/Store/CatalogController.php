<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Ordering\Checkout;
use App\Provisioning\ModuleRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The public product catalogue. */
class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $currency = Checkout::currencyFor($request->user('client'));
        $groups = ProductGroup::where('visible', true)->ordered()
            ->with(['products' => fn ($query) => $this->sellable($query, $currency)])
            ->get()
            ->filter(fn (ProductGroup $group) => $group->products->isNotEmpty());
        $ungrouped = $this->sellable(Product::query(), $currency)->whereNull('product_group_id')->orderBy('sort_order')->orderBy('name')->get();

        return view('store.index', compact('groups', 'ungrouped', 'currency'));
    }

    public function group(Request $request, ProductGroup $group): View
    {
        abort_unless($group->visible, 404);
        $currency = Checkout::currencyFor($request->user('client'));
        $products = $this->sellable($group->products(), $currency)->get();

        return view('store.group', compact('group', 'products', 'currency'));
    }

    public function product(Request $request, Product $product, ModuleRegistry $modules): View
    {
        $currency = Checkout::currencyFor($request->user('client'));
        $product = $this->sellable(Product::whereKey($product->id), $currency)
            ->with(['group', 'options.choices', 'addons' => fn ($query) => $query->where('active', true)->with('prices')])
            ->firstOrFail();
        abort_if($product->group && ! $product->group->visible, 404);

        return view('store.product', [
            'product' => $product,
            'currency' => $currency,
            'prices' => $product->pricesIn($currency),
            'addons' => $product->addons->filter(fn (Product $addon) => $addon->pricesIn($currency)->isNotEmpty()),
            'fields' => CustomField::forService($product->id)->where('admin_only', false)->get(),
            'needsDomain' => $modules->needsDomain($product->module),
        ]);
    }

    /** Active, non-add-on products with at least one price in the currency. */
    private function sellable(Builder|HasMany $query, string $currency): Builder|HasMany
    {
        return $query->orderable()
            ->whereHas('prices', fn (Builder $prices) => $prices->where('currency', $currency))
            ->with(['prices' => fn ($prices) => $prices->where('currency', $currency)]);
    }
}
