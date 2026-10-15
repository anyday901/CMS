<?php

namespace App\Http\Controllers\Portal;

use App\Auth\PortalContact;
use App\Billing\ServiceCanceller;
use App\Billing\ServiceUpgrader;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\UsesServers;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(Request $request): View
    {
        $services = $request->user('client')->services()->with('product')->latest()->get();

        return view('portal.services.index', compact('services'));
    }

    public function show(Request $request, int $service, ServiceUpgrader $upgrader, ServiceCanceller $canceller, ModuleRegistry $modules): View
    {
        $service = $request->user('client')->services()
            ->with(['product.upgrades', 'server', 'pendingCancellation', 'openMove', 'parent.product', 'addons.product', 'upgrades' => fn ($query) => $query->where('status', ServiceUpgrade::PENDING)->with(['toProduct', 'invoice'])])
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
            'canUpgrade' => PortalContact::allows('orders') && $service->product->upgrades->where('active', true)->isNotEmpty() && $upgrader->problem($service) === null,
            'canCancel' => PortalContact::allows('orders') && $canceller->problem($service) === null,
            'canOrder' => PortalContact::allows('orders'),
            'login' => $this->login($service, $modules),
            ...ServiceAccountController::abilities($service, $modules),
        ]);
    }

    /**
     * Where and how the client logs in to a service on a server, once the
     * module has set it up and while it's running.
     *
     * @return array<string, string>
     */
    private function login(Service $service, ModuleRegistry $modules): array
    {
        $module = $modules->find($service->product->module);
        $running = in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true);

        return $module instanceof UsesServers && $running && $service->provisioned_at !== null
            ? $module->loginDetails($service)
            : [];
    }
}
