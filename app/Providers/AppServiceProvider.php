<?php

namespace App\Providers;

use App\Billing\ServiceUpgrader;
use App\Console\RecordsScheduledTasks;
use App\Enums\InvoiceStatus;
use App\Enums\StaffRole;
use App\Events\InvoiceIssued;
use App\Events\InvoicePaid;
use App\Events\OrderPlaced;
use App\Events\ServiceActivated;
use App\Events\ServiceChanged;
use App\Events\ServiceSuspended;
use App\Events\ServiceTerminated;
use App\Events\ServiceUnsuspended;
use App\Mail\ClientMailer;
use App\Models\ClientContact;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Provisioning\AccountMover;
use App\Provisioning\Provisioner;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (StaffRole::ABILITIES as $ability) {
            Gate::define($ability, fn (User $user) => $user->allows($ability));
        }

        Settings::apply();
        RecordsScheduledTasks::listen();

        // Contacts' password links only find contacts who can sign in.
        Auth::provider('contacts', fn ($app) => (new EloquentUserProvider($app['hash'], ClientContact::class))
            ->withQuery(fn ($query) => $query->where('portal_access', true)));

        // Module actions follow service status changes, after they are saved.
        foreach ([
            ServiceActivated::class => 'create',
            ServiceChanged::class => 'change_package',
            ServiceSuspended::class => 'suspend',
            ServiceUnsuspended::class => 'unsuspend',
            ServiceTerminated::class => 'terminate',
        ] as $event => $action) {
            Event::listen($event, fn ($e) => app(Provisioner::class)->queue($e->service, $action));
        }

        // A service that ends mid-move loses its old account too.
        Event::listen(ServiceTerminated::class, fn (ServiceTerminated $e) => app(AccountMover::class)->serviceEnded($e->service));

        // An upgrade waits for its invoice; paying it moves the service.
        Event::listen(InvoicePaid::class, function (InvoicePaid $e) {
            ServiceUpgrade::where('invoice_id', $e->invoice->id)->where('status', ServiceUpgrade::PENDING)->get()
                ->each(fn (ServiceUpgrade $upgrade) => app(ServiceUpgrader::class)->apply($upgrade));
        });

        // Client emails. An invoice paid from credit as it is issued gets only the receipt.
        Event::listen(InvoiceIssued::class, function (InvoiceIssued $e) {
            if ($e->invoice->status !== InvoiceStatus::Unpaid) {
                return;
            }
            $order = $e->invoice->order;
            app(ClientMailer::class)->send(
                $e->invoice->client,
                $order ? 'order_invoice' : 'invoice_created',
                $order ? ['order_number' => $order->id] : [],
                invoice: $e->invoice,
            );
        });
        Event::listen(OrderPlaced::class, fn (OrderPlaced $e) => app(ClientMailer::class)->orderConfirmation($e->order));
        Event::listen(InvoicePaid::class, fn (InvoicePaid $e) => app(ClientMailer::class)->send($e->invoice->client, 'payment_receipt', invoice: $e->invoice));
        Event::listen(ServiceActivated::class, function (ServiceActivated $e) {
            $service = $e->service;
            app(ClientMailer::class)->send($service->client, 'service_welcome', [
                'service_name' => $service->description(),
                'product_name' => $service->product->name,
                'service_url' => route('portal.services.show', $service),
                'next_due_date' => $service->next_due_date?->format('M j, Y') ?? '',
                'recurring_amount' => Money::format((int) $service->recurring_amount, $service->client->currency),
                'product_welcome' => trim((string) $service->product->welcome_email),
            ]);
        });
    }
}
