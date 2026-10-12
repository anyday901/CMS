<?php

namespace App\Http\Controllers\Portal;

use App\Billing\ServiceUpgrader;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\Product;
use App\Models\ServiceUpgrade;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(Request $request): View
    {
        $services = $request->user('client')->services()->with('product')->latest()->get();

        return view('portal.services.index', compact('services'));
    }

    public function show(Request $request, int $service, ServiceUpgrader $upgrader): View
    {
        $service = $request->user('client')->services()
            ->with(['product.upgrades', 'parent.product', 'addons.product', 'upgrades' => fn ($query) => $query->where('status', ServiceUpgrade::PENDING)->with(['toProduct', 'invoice'])])
            ->findOrFail($service);
        $currency = $request->user('client')->currency;
        $owned = $service->addons->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended])->pluck('product_id');

        return view('portal.services.show', [
            'service' => $service,
            'fields' => CustomField::forService($service->product_id)->where('admin_only', false)->get(),
            // Add-ons the client can still order for this service.
            'addons' => $service->status === ServiceStatus::Active
                ? $service->product->addons()->where('active', true)->with('prices')->get()
                    ->reject(fn (Product $addon) => $owned->contains($addon->id) || $addon->addonCycleFor($service->billing_cycle, $currency) === null)
                : collect(),
            'canUpgrade' => $service->product->upgrades->where('active', true)->isNotEmpty() && $upgrader->problem($service) === null,
        ]);
    }
}
