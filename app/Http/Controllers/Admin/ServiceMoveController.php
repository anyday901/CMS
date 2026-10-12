<?php

namespace App\Http\Controllers\Admin;

use App\Billing\ServiceUpgrader;
use App\Billing\UpgradeQuote;
use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Staff move a service to another product or billing cycle, charging for it or not. */
class ServiceMoveController extends Controller
{
    public function __construct(private ServiceUpgrader $upgrader) {}

    /** The move form, and with a product and cycle chosen, what the move costs. */
    public function show(Request $request, Service $service): View
    {
        $service->load(['client', 'product', 'promotion']);
        $quote = null;

        if ($request->filled('product_id')) {
            $quote = $this->quote($request, $service);
        }

        return view('admin.services.move', [
            'service' => $service,
            'products' => Product::where('active', true)->orWhere('id', $service->product_id)->with(['prices', 'options.choices'])->orderBy('name')->get(),
            'quote' => $quote,
            'charge' => $request->boolean('charge', true),
            'problem' => $this->upgrader->staffProblem($service, $request->boolean('charge', true)),
            'canMoveFree' => $this->upgrader->staffProblem($service, false) === null,
        ]);
    }

    public function store(Request $request, Service $service): RedirectResponse
    {
        $data = $this->validated($request);
        $product = Product::findOrFail($data['product_id']);

        try {
            $upgrade = $this->upgrader->staffMove($service, $product, BillingCycle::from($data['billing_cycle']), $data['options'] ?? [], $request->boolean('charge'), $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['move' => $e->getMessage()]);
        }

        return $upgrade->invoice
            ? redirect()->route('admin.invoices.show', $upgrade->invoice)->with('status', "Invoiced the move to {$product->name}. It happens once this invoice is paid.")
            : redirect()->route('admin.services.show', $service)->with('status', "Moved to {$product->name}.");
    }

    private function quote(Request $request, Service $service): UpgradeQuote
    {
        $data = $this->validated($request);

        try {
            return $this->upgrader->staffQuote($service, Product::findOrFail($data['product_id']), BillingCycle::from($data['billing_cycle']), $data['options'] ?? [], $request->boolean('charge'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['move' => $e->getMessage()]);
        }
    }

    /** @return array{product_id: int|string, billing_cycle: string, options?: array<int|string, mixed>} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'options' => ['array'],
        ]);
    }
}
