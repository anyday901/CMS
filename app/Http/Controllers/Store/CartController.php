<?php

namespace App\Http\Controllers\Store;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\Product;
use App\Ordering\Cart;
use App\Ordering\Checkout;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\ServerAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class CartController extends Controller
{
    public function __construct(private Cart $cart, private Checkout $checkout) {}

    public function index(Request $request): View
    {
        return view('store.cart', ['quote' => $this->checkout->quote($this->cart, $request->user('client'))]);
    }

    /**
     * Adds a product, as configured on its order page, with any add-ons
     * ticked there. Hosting on a server needs the domain it's set up for.
     */
    public function store(Request $request, Product $product, ModuleRegistry $modules): RedirectResponse
    {
        $fields = CustomField::forService($product->id)->where('admin_only', false)->get();
        $needsDomain = $modules->needsDomain($product->module);
        $data = $request->validate([
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'options' => ['array'],
            'label' => $needsDomain ? ['required', 'string', 'max:255', ServerAccount::domainRule()] : ['nullable', 'string', 'max:255'],
            'addons' => ['array'],
            'addons.*' => ['integer'],
        ] + CustomField::rules($fields), ['label.required' => 'Enter the domain name for this service, such as example.com.'], CustomField::attributeNames($fields));

        $label = trim((string) ($data['label'] ?? ''));
        $label = $needsDomain ? strtolower($label) : $label;

        $this->add([
            'product_id' => $product->id,
            'billing_cycle' => $data['billing_cycle'],
            'options' => $data['options'] ?? [],
            'custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), null),
            'label' => $label !== '' ? $label : null,
            'addons' => array_values(array_unique(array_map('intval', $data['addons'] ?? []))),
        ], $request, 'billing_cycle');

        return redirect()->route('cart.index')->with('status', "{$product->name} is in your cart.");
    }

    /** Adds an add-on for a service the client already has. */
    public function addon(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
        ]);

        foreach ($this->cart->lines() as $line) {
            if (($line['parent_service_id'] ?? null) === (int) $data['service_id'] && $line['product_id'] === (int) $data['product_id']) {
                throw ValidationException::withMessages(['product_id' => 'That add-on is already in your cart.']);
            }
        }

        $this->add(['product_id' => (int) $data['product_id'], 'parent_service_id' => (int) $data['service_id']], $request, 'product_id');

        return redirect()->route('cart.index')->with('status', 'The add-on is in your cart.');
    }

    public function destroy(string $line): RedirectResponse
    {
        $this->cart->remove($line);

        return redirect()->route('cart.index')->with('status', 'Removed from your cart.');
    }

    public function promotion(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50']]);
        $this->cart->setPromotionCode($data['code']);
        $quote = $this->checkout->quote($this->cart, $request->user('client'));

        if ($quote->promotionError !== null) {
            $this->cart->setPromotionCode(null);

            throw ValidationException::withMessages(['code' => $quote->promotionError]);
        }

        return redirect()->route('cart.index')->with('status', "Code {$quote->promotion->code} applied: {$quote->promotion->summary()}.");
    }

    public function removePromotion(): RedirectResponse
    {
        $this->cart->setPromotionCode(null);

        return redirect()->route('cart.index')->with('status', 'Promotion code removed.');
    }

    /** @param  array<string, mixed>  $line */
    private function add(array $line, Request $request, string $errorKey): void
    {
        if ($this->cart->isFull()) {
            throw ValidationException::withMessages([$errorKey => 'Your cart is full. Check out or remove something first.']);
        }

        try {
            $this->checkout->check($line, $request->user('client'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([$errorKey => $e->getMessage()]);
        }

        $this->cart->add($line);
    }
}
