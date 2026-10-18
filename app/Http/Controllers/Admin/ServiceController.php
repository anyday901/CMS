<?php

namespace App\Http\Controllers\Admin;

use App\Billing\ConfigurableOptions;
use App\Billing\OrderChoices;
use App\Billing\OrderService;
use App\Billing\ServiceCanceller;
use App\Billing\ServiceLifecycle;
use App\Billing\ServiceUpgrader;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\AccountMover;
use App\Provisioning\ControlPanelLogin;
use App\Provisioning\CopiesAccounts;
use App\Provisioning\ModuleError;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Provisioner;
use App\Provisioning\ServerAccount;
use App\Provisioning\SingleSignOn;
use App\Provisioning\UsesServers;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

    public function show(Service $service, ServiceUpgrader $upgrader, ServiceCanceller $canceller, ModuleRegistry $modules, AccountMover $mover): View
    {
        $service->load(['client.group', 'client.clientNotes.user', 'product.options.choices', 'options', 'invoiceItems.invoice', 'promotion', 'order', 'parent.product', 'addons.product', 'upgrades.toProduct', 'upgrades.invoice', 'pendingCancellation.requester', 'server', 'openMove.fromServer', 'openMove.fromProduct']);
        $module = $modules->find($service->product->module);
        $servers = $module instanceof UsesServers ? Server::where('module', $module->key())->withAccountCount()->orderBy('name')->get() : null;
        $moveTo = $servers !== null && $mover->problem($service) === null
            ? $servers->filter(fn (Server $server) => $server->active && $server->id !== $service->server_id && $server->hasRoom())
            : collect();

        return view('admin.services.show', [
            'service' => $service,
            'client' => $service->client,
            // The client page's menu, which the service is shown inside.
            ...ClientController::frame($service->client),
            // The client's other services, for the picker.
            'clientServices' => $service->client->services()->with('product')->latest()->get(),
            // Servers it can be put on: of its module's kind when the module uses them, otherwise any.
            'serverChoices' => $servers ?? Server::orderBy('name')->get(),
            'module' => $module,
            // Servers the service can be put on, for modules that use them.
            'servers' => $servers,
            // Servers the account can move to, when it can move.
            'moveTo' => $moveTo,
            // Why the module can't copy the account to each of those itself, by server id; null when it copies by hand only.
            'copyProblems' => $module instanceof CopiesAccounts ? $moveTo->mapWithKeys(fn (Server $server) => [$server->id => $mover->copyProblem($service, $server)]) : null,
            'canLogIn' => $module instanceof SingleSignOn && $service->status === ServiceStatus::Active && $service->provisioned_at !== null,
            'fields' => CustomField::forService($service->product_id)->get(),
            'canMove' => $upgrader->staffProblem($service, false) === null,
            'canCancel' => $canceller->problem($service) === null,
        ]);
    }

    /**
     * Custom fields and configurable options. Options added are priced at
     * today's prices and options removed come off at the price they were
     * billed at; the difference applies from the next invoice, and the
     * current period isn't re-invoiced.
     */
    public function update(Request $request, Service $service, ConfigurableOptions $options): RedirectResponse
    {
        $fields = CustomField::forService($service->product_id)->get();
        $request->validate(['options' => ['array']] + CustomField::rules($fields), [], CustomField::attributeNames($fields));

        try {
            $selected = $options->select($service->product, (array) $request->input('options', []), $service->billing_cycle, $service->options, $service->client->currency);
            $options->assertCurrency($selected, $service->client->currency);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['options' => $e->getMessage()]);
        }

        DB::transaction(function () use ($request, $service, $options, $selected, $fields) {
            $before = $service->optionSummary();
            $change = $options->recurring($selected) - $options->recurring($service->options);

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

    /**
     * The fields staff edit on the service page, as in WHMCS: the server,
     * domain and login, and the dates, amount and cycle it bills on. Any
     * service can be put on a server, of its module's kind when the module
     * uses servers; choosing another server doesn't move the account. Once a
     * module's account exists, a new password is set on the server, and
     * waits in new_password until that works so the client keeps a password
     * that logs in. Only the fields sent are changed, and billing changes
     * take effect from the next invoice.
     */
    public function details(Request $request, Service $service, Provisioner $provisioner, ModuleRegistry $modules): RedirectResponse
    {
        $module = $modules->find($service->product->module);
        $usesServers = $module instanceof UsesServers;

        $data = $request->validate([
            'server_id' => ['nullable', $usesServers ? Rule::exists('servers', 'id')->where('module', $module->key()) : Rule::exists('servers', 'id')],
            'label' => ['nullable', 'string', 'max:255', ...($usesServers && $module->needsDomain() ? [ServerAccount::domainRule()] : [])],
            // A module's username has to work on the server; one kept for reference, such as from WHMCS, can be anything.
            'username' => $usesServers ? ['nullable', 'string', 'max:64', 'regex:/^\S+$/'] : ['nullable', 'string', 'max:255'],
            'password' => $usesServers ? ['nullable', 'string', 'min:8', 'max:64'] : ['nullable', 'string', 'max:255'],
            'registration_date' => ['sometimes', 'required', 'date'],
            'next_due_date' => ['sometimes', 'nullable', 'date', Rule::requiredIf(fn () => BillingCycle::tryFrom((string) $request->input('billing_cycle', $service->billing_cycle->value))?->isRecurring())],
            'recurring_amount' => ['sometimes', 'required', Money::rule()],
            'billing_cycle' => ['sometimes', 'required', Rule::enum(BillingCycle::class)],
        ], ['username.regex' => 'The username can\'t contain spaces.', 'next_due_date.required' => 'A recurring service needs a next due date.']);

        $newPassword = filled($data['password'] ?? null) && $data['password'] !== $service->password;
        // Before the account exists, create uses the new password anyway.
        $push = $newPassword && $usesServers && $service->provisioned_at !== null && Provisioner::canChangePassword($module, $service)
            && in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true);

        $passwordColumn = $push ? 'new_password' : 'password';
        $service->forceFill([
            ...Arr::only($data, ['server_id', 'label', 'username', 'registration_date', 'next_due_date', 'billing_cycle']),
            ...($newPassword ? [$passwordColumn => $data['password']] : []),
            ...(isset($data['recurring_amount']) ? ['recurring_amount' => Money::parse($data['recurring_amount'])] : []),
        ]);
        $changed = array_keys($service->getDirty());
        $service->save();

        if ($changed !== []) {
            Activity::record("Changed the details of {$service->description()}", $service, ['changed' => $changed]);
        }

        if ($push) {
            $provisioner->queue($service, 'change_password');
        }

        return back()->with('status', $push ? 'Saved. The new password is being set on the server.' : 'Saved.');
    }

    /** Opens the service's control panel, logged in as its account, for staff helping the client. */
    public function controlPanel(Request $request, Service $service, ModuleRegistry $modules): RedirectResponse
    {
        $module = $modules->find($service->product->module);
        abort_unless($module instanceof SingleSignOn && $service->provisioned_at !== null, 422, 'This service\'s module can\'t log in to its control panel.');

        try {
            $url = app(ControlPanelLogin::class)->url($service, $module, $request->ip());
        } catch (ModuleError $e) {
            return back()->withErrors(['account' => $e->getMessage()]);
        }

        Activity::record("Logged in to the control panel of {$service->description()}", $service);

        return redirect()->away($url);
    }

    /** Frees the service's software license from where it was installed. */
    public function reissueLicense(Service $service): RedirectResponse
    {
        abort_if($service->license === null, 404);
        $service->license->reissue();
        Activity::record("Reissued the license of {$service->description()}", $service);

        return back()->with('status', 'License reissued. It locks to the next place it is installed.');
    }

    /** Runs a module action again, usually after fixing what made it fail. */
    public function provision(Request $request, Service $service, Provisioner $provisioner, ModuleRegistry $modules): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(Provisioner::ACTIONS)]]);

        $module = $modules->find($service->product->module);
        abort_if($module === null, 422, 'This service\'s product has no provisioning module.');
        abort_unless(in_array($data['action'], Provisioner::staffActions($module, $service), true), 422, 'This module can\'t run that action now.');

        $provisioner->queue($service, $data['action'], retry: true);
        Activity::record("Queued {$data['action']} on the provisioning module for {$service->description()}", $service);

        return back()->with('status', 'Provisioning action queued.');
    }
}
