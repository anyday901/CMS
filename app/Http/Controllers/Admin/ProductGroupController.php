<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ProductGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductGroupController extends Controller
{
    public function index(): View
    {
        $groups = ProductGroup::withCount('products')->ordered()->get();

        return view('admin.product-groups.index', ['groups' => $groups, 'group' => new ProductGroup]);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = ProductGroup::create($this->validated($request, new ProductGroup));
        Activity::record("Added product group {$group->name}", $group);

        return redirect()->route('admin.product-groups.index')->with('status', 'Group added.');
    }

    public function edit(ProductGroup $productGroup): View
    {
        return view('admin.product-groups.edit', ['group' => $productGroup]);
    }

    public function update(Request $request, ProductGroup $productGroup): RedirectResponse
    {
        $productGroup->update($this->validated($request, $productGroup));
        Activity::record("Updated product group {$productGroup->name}", $productGroup);

        return redirect()->route('admin.product-groups.index')->with('status', 'Group updated.');
    }

    /** Its products stay, without a group. */
    public function destroy(ProductGroup $productGroup): RedirectResponse
    {
        $productGroup->delete();
        Activity::record("Deleted product group {$productGroup->name}");

        return redirect()->route('admin.product-groups.index')->with('status', 'Group deleted.');
    }

    private function validated(Request $request, ProductGroup $group): array
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // "order" would clash with the product order pages under /store/order.
            'slug' => ['required', 'string', 'max:255', 'not_in:order', Rule::unique('product_groups')->ignore($group)],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], ['slug.required' => 'Enter a name or web address made of letters or numbers.']);

        return [...$data, 'sort_order' => (int) ($data['sort_order'] ?? 0), 'visible' => $request->boolean('visible')];
    }
}
