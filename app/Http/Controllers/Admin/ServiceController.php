<?php

namespace App\Http\Controllers\Admin;

use App\Billing\ConfigurableOptions;
use App\Billing\OrderChoices;
use App\Billing\OrderService;
use App\Billing\ServiceLifecycle;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Provisioner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class ServiceController extends Controller
{
    public function create(Client $client): View
    {
        $products = Product::where('active', true)->with(['prices', 'options.choices'])->orderBy('name')->get();
        $serviceFields = CustomField::where('entity', CustomField::SERVICE)->ordered()->get();

        return view('admin.services.create', compact('client', 'products', 'serviceFields'));
    }

    public function store(Request $request, Client $client, OrderService $orders): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'start_date' => ['required', 'date'],
            'label' => ['nullable', 'string', 'max:255'],
            'options' => ['array'],
        ]);
        $fields = CustomField::forService((int) $data['product_id'])->get();
        $request->validate(CustomField::rules($fields), [], CustomField::attributeNames($fields));

        try {
            $result = $orders->create(
                $client,
                Product::findOrFail($data['product_id']),
                BillingCycle::from($data['billing_cycle']),
                CarbonImmutable::parse($data['start_date']),
                $data['label'] ?? null,
                $request->boolean('invoice'),
                new OrderChoices($data['options'] ?? [], CustomField::merge($fields, (array) $request->input('custom_fields', []), null)),
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
        $service->load(['client', 'product.options.choices', 'options', 'invoiceItems.invoice']);

        return view('admin.services.show', [
            'service' => $service,
            'fields' => CustomField::forService($service->product_id)->get(),
        ]);
    }

    /**
     * Custom fields and configurable options. A change in option prices is
     * added to or taken off the recurring amount from the next invoice; the
     * current period isn't re-invoiced.
     */
    public function update(Request $request, Service $service, ConfigurableOptions $options): RedirectResponse
    {
        $fields = CustomField::forService($service->product_id)->get();
        $request->validate(['options' => ['array']] + CustomField::rules($fields), [], CustomField::attributeNames($fields));

        try {
            $selected = $options->select($service->product, (array) $request->input('options', []));
            $options->assertCurrency($selected, $service->client, $service->billing_cycle);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['options' => $e->getMessage()]);
        }

        DB::transaction(function () use ($request, $service, $options, $selected, $fields) {
            $before = $service->optionSummary();
            $change = $options->recurring($selected, $service->billing_cycle) - $options->recurring($service->options->load(['option', 'choice']), $service->billing_cycle);

            $options->save($service, $selected);
            $service->forceFill([
                'recurring_amount' => max(0, $service->recurring_amount + $change),
                'custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), $service->custom_fields),
            ])->save();

            Activity::record("Updated {$service->description()}", $service, array_filter([
                'options' => $before !== $service->optionSummary() ? ['from' => $before, 'to' => $service->optionSummary()] : null,
                'recurring_change' => $change ?: null,
            ]));
        });

        return back()->with('status', 'Service updated.');
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
