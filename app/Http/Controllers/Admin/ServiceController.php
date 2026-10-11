<?php

namespace App\Http\Controllers\Admin;

use App\Billing\OrderService;
use App\Billing\ServiceLifecycle;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Provisioner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class ServiceController extends Controller
{
    public function create(Client $client): View
    {
        $products = Product::where('active', true)->with('prices')->orderBy('name')->get();

        return view('admin.services.create', compact('client', 'products'));
    }

    public function store(Request $request, Client $client, OrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'start_date' => ['required', 'date'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $orders->create(
                $client,
                Product::findOrFail($data['product_id']),
                BillingCycle::from($data['billing_cycle']),
                CarbonImmutable::parse($data['start_date']),
                $data['label'] ?? null,
                $request->boolean('invoice'),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['billing_cycle' => $e->getMessage()]);
        }

        return $result['invoice']
            ? redirect()->route('admin.invoices.show', $result['invoice'])->with('status', 'Service created and invoiced.')
            : redirect()->route('admin.services.show', $result['service'])->with('status', 'Service created.');
    }

    public function show(Service $service): View
    {
        $service->load(['client', 'product', 'invoiceItems.invoice']);

        return view('admin.services.show', compact('service'));
    }

    public function action(Service $service, string $action, ServiceLifecycle $lifecycle): RedirectResponse
    {
        $allowed = match ($action) {
            'suspend' => $service->status === ServiceStatus::Active,
            'unsuspend' => $service->status === ServiceStatus::Suspended,
            'terminate' => in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending], true),
        };

        abort_unless($allowed, 422, "Cannot {$action} a {$service->status->value} service.");

        match ($action) {
            'suspend' => $lifecycle->suspend($service, 'manual'),
            'unsuspend' => $lifecycle->unsuspend($service),
            'terminate' => $lifecycle->terminate($service),
        };

        return back()->with('status', 'Service '.$service->status->value.'.');
    }

    /** Runs a module action again, usually after fixing what made it fail. */
    public function provision(Request $request, Service $service, Provisioner $provisioner, ModuleRegistry $modules): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(Provisioner::ACTIONS)]]);

        abort_if($modules->find($service->product->module) === null, 422, 'This service\'s product has no provisioning module.');

        $provisioner->queue($service, $data['action'], retry: true);
        Activity::record("Queued {$data['action']} on the provisioning module for {$service->description()}", $service);

        return back()->with('status', 'Provisioning action queued.');
    }
}
