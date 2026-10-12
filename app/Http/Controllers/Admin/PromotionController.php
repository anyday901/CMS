<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Product;
use App\Models\Promotion;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function index(): View
    {
        return view('admin.promotions.index', [
            'promotions' => Promotion::orderByDesc('active')->orderBy('code')->get(),
            'products' => Product::orderBy('name')->pluck('name', 'id'),
            'promotion' => new Promotion,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $promotion = Promotion::create($this->validated($request, new Promotion));
        Activity::record("Added promotion code {$promotion->code}", $promotion, ['discount' => $promotion->summary()]);

        return redirect()->route('admin.promotions.index')->with('status', 'Promotion code added.');
    }

    public function edit(Promotion $promotion): View
    {
        return view('admin.promotions.edit', [
            'promotion' => $promotion,
            'products' => Product::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update(Request $request, Promotion $promotion): RedirectResponse
    {
        $promotion->update($this->validated($request, $promotion));
        Activity::record("Updated promotion code {$promotion->code}", $promotion, ['discount' => $promotion->summary()]);

        return redirect()->route('admin.promotions.index')->with('status', 'Promotion code updated. Services already using it keep the discount they were given.');
    }

    /** A code that has been used stays, so its orders still name it; turn it off instead. */
    public function destroy(Promotion $promotion): RedirectResponse
    {
        abort_if($promotion->uses > 0, 422, 'This code has been used. Turn it off instead of deleting it.');
        $promotion->delete();
        Activity::record("Deleted promotion code {$promotion->code}");

        return redirect()->route('admin.promotions.index')->with('status', 'Promotion code deleted.');
    }

    private function validated(Request $request, Promotion $promotion): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('promotions')->ignore($promotion)],
            'type' => ['required', Rule::in(array_keys(Promotion::TYPES))],
            'value' => ['required', Money::rule()],
            'product_ids' => ['array'],
            'product_ids.*' => [Rule::exists('products', 'id')],
            'starts_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['code.regex' => 'Use only letters, numbers, dashes and underscores in the code.']);

        // Money::parse reads "10" as 1000: cents for a fixed amount, hundredths of a percent for a percent.
        $value = Money::parse($data['value']);

        if ($value === 0 || ($data['type'] === Promotion::PERCENT && $value > 10000)) {
            throw ValidationException::withMessages(['value' => 'Enter a discount above zero, and no more than 100%.']);
        }

        return [
            ...$data,
            'value' => $value,
            'product_ids' => array_values(array_map('intval', $data['product_ids'] ?? [])) ?: null,
            'max_uses' => $data['max_uses'] ?? null,
            'recurring' => $request->boolean('recurring'),
            'once_per_client' => $request->boolean('once_per_client'),
            'active' => $request->boolean('active'),
        ];
    }
}
