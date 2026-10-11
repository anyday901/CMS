<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionChoice;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Configurable options on a product, and the choices and prices of each. */
class ProductOptionController extends Controller
{
    /** Blank rows offered for new dropdown choices. */
    private const NEW_ROWS = 3;

    public function index(Product $product): View
    {
        return view('admin.product-options.index', [
            'product' => $product,
            'options' => $product->options()->with('choices')->get(),
        ]);
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(ProductOption::TYPES))],
        ]);

        $option = $product->options()->create($data + ['sort_order' => (int) $product->options()->max('sort_order') + 1]);
        if ($option->hasOneChoice()) {
            $option->choices()->create(['name' => $option->name]);
        }
        Activity::record("Added option {$option->name} to {$product->name}", $product);

        return redirect()->route('admin.product-options.edit', $option)->with('status', 'Option added. Now set its prices.');
    }

    public function edit(ProductOption $productOption): View
    {
        $option = $productOption->load(['product', 'choices']);
        $rows = $option->choices->all();
        if (! $option->hasOneChoice()) {
            $rows = array_merge($rows, array_fill(0, self::NEW_ROWS, new ProductOptionChoice));
        }

        return view('admin.product-options.edit', ['option' => $option, 'rows' => $rows, 'cycles' => $this->cycles($option->product)]);
    }

    public function update(Request $request, ProductOption $productOption): RedirectResponse
    {
        $option = $productOption;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'min_quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_quantity' => ['nullable', 'integer', 'min:1', 'max:100000', 'gte:min_quantity'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'choices' => ['array'],
            'choices.*.id' => ['nullable', 'integer'],
            'choices.*.name' => ['nullable', 'string', 'max:255'],
            'choices.*.setup_fee' => ['nullable', Money::rule()],
            'choices.*.prices' => ['array'],
            'choices.*.prices.*' => ['nullable', Money::rule()],
        ]);

        DB::transaction(function () use ($option, $data, $request) {
            $option->update([
                'name' => $data['name'],
                'min_quantity' => (int) ($data['min_quantity'] ?? 0),
                'max_quantity' => $data['max_quantity'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? $option->sort_order),
            ]);

            foreach (array_values($data['choices'] ?? []) as $i => $row) {
                $this->saveChoice($option, $row, $i, $request->boolean("choices.{$i}.delete"));
            }

            if ($option->hasOneChoice()) {
                $option->choices()->first()?->update(['name' => $option->name]);
            }
        });
        Activity::record("Updated option {$option->name} on {$option->product->name}", $option->product);

        return redirect()->route('admin.product-options.index', $option->product)->with('status', 'Option saved.');
    }

    public function destroy(ProductOption $productOption): RedirectResponse
    {
        if ($productOption->inUse()) {
            return back()->withErrors(['option' => "{$productOption->name} is used by services, so it can't be deleted."]);
        }

        $productOption->delete();
        Activity::record("Deleted option {$productOption->name} from {$productOption->product->name}", $productOption->product);

        return redirect()->route('admin.product-options.index', $productOption->product)->with('status', 'Option deleted.');
    }

    private function saveChoice(ProductOption $option, array $row, int $i, bool $delete): void
    {
        $choice = filled($row['id'] ?? null) ? $option->choices()->find((int) $row['id']) : null;
        $name = trim((string) ($row['name'] ?? ''));

        if ($choice && $delete) {
            if ($choice->inUse()) {
                throw ValidationException::withMessages(["choices.{$i}.delete" => "{$choice->name} is used by services, so it can't be removed."]);
            }
            $choice->delete();

            return;
        }

        if ($choice === null && ($name === '' || $option->hasOneChoice())) {
            return;
        }

        $attributes = [
            'name' => $name !== '' ? $name : ($choice->name ?? $option->name),
            'setup_fee' => filled($row['setup_fee'] ?? null) ? Money::parse($row['setup_fee']) : 0,
            'prices' => collect($row['prices'] ?? [])
                ->filter(fn ($price, $cycle) => filled($price) && BillingCycle::tryFrom($cycle) !== null)
                ->map(fn ($price) => Money::parse($price))
                ->all() ?: null,
            'sort_order' => $i,
        ];

        $choice ? $choice->update($attributes) : $option->choices()->create($attributes);
    }

    /** @return list<BillingCycle> The cycles the product is sold on, or every cycle when it has no prices yet. */
    private function cycles(Product $product): array
    {
        $offered = $product->prices()->where('currency', config('billing.currency'))->pluck('billing_cycle')->map->value->all();
        $cycles = array_values(array_filter(BillingCycle::cases(), fn (BillingCycle $cycle) => in_array($cycle->value, $offered, true)));

        return $cycles ?: BillingCycle::cases();
    }
}
