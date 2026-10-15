<?php

namespace App\Http\Controllers\Portal;

use App\Billing\ServiceUpgrader;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\AccountMover;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Clients move their own services to another product. */
class UpgradeController extends Controller
{
    public function __construct(private ServiceUpgrader $upgrader) {}

    public function index(Request $request, int $service): View
    {
        $service = $this->service($request, $service);
        $currency = $service->client->currency;
        $products = $service->product->upgrades()->where('active', true)->with('prices')->get()
            ->filter(fn (Product $product) => $product->priceFor($service->billing_cycle, $currency) !== null);

        return view('portal.services.upgrade', [
            'service' => $service,
            'products' => $products,
            'problem' => $this->upgrader->problem($service),
        ]);
    }

    /** The new product's options, and with them chosen (or none to choose), the prorated price. */
    public function show(Request $request, int $service, Product $product, AccountMover $mover): View|RedirectResponse
    {
        $service = $this->service($request, $service);
        $product->load('options.choices');
        $choosing = $product->options->isNotEmpty() && ! $request->has('options');
        $quote = null;

        if (! $choosing) {
            try {
                $quote = $this->upgrader->quote($service, $product, (array) $request->input('options', []));
            } catch (InvalidArgumentException $e) {
                return redirect()->route('portal.services.upgrade', $service)->withErrors(['upgrade' => $e->getMessage()]);
            }
        }

        return view('portal.services.upgrade-confirm', [
            'service' => $service,
            'product' => $product,
            'quote' => $quote,
            'values' => (array) $request->input('options', []),
            'migrates' => $mover->migrates($service->product, $product),
        ]);
    }

    public function store(Request $request, int $service, Product $product): RedirectResponse
    {
        $service = $this->service($request, $service);
        $request->validate(['options' => ['array']]);

        try {
            $upgrade = $this->upgrader->request($service, $product, (array) $request->input('options', []));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['upgrade' => $e->getMessage()]);
        }

        return $upgrade->invoice
            ? redirect()->route('portal.invoices.show', $upgrade->invoice)->with('status', __('Pay this invoice to move to :product.', ['product' => $product->name]))
            : redirect()->route('portal.services.show', $service)->with('status', __('Your service is now on :product.', ['product' => $product->name]));
    }

    private function service(Request $request, int $id): Service
    {
        return $request->user('client')->services()->with(['product', 'client', 'promotion'])->findOrFail($id);
    }
}
