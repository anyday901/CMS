<?php

namespace App\Providers;

use App\Enums\InvoiceStatus;
use App\Enums\StaffRole;
use App\Events\InvoiceIssued;
use App\Events\InvoicePaid;
use App\Events\ServiceActivated;
use App\Events\ServiceSuspended;
use App\Events\ServiceTerminated;
use App\Events\ServiceUnsuspended;
use App\Mail\ClientMailer;
use App\Models\User;
use App\Provisioning\Provisioner;
use App\Support\Settings;
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

        // Module actions follow service status changes, after they are saved.
        foreach ([
            ServiceActivated::class => 'create',
            ServiceSuspended::class => 'suspend',
            ServiceUnsuspended::class => 'unsuspend',
            ServiceTerminated::class => 'terminate',
        ] as $event => $action) {
            Event::listen($event, fn ($e) => app(Provisioner::class)->queue($e->service, $action));
        }

        // Client emails. An invoice paid from credit as it is issued gets only the receipt.
        Event::listen(InvoiceIssued::class, function (InvoiceIssued $e) {
            if ($e->invoice->status === InvoiceStatus::Unpaid) {
                app(ClientMailer::class)->send($e->invoice->client, 'invoice_created', invoice: $e->invoice);
            }
        });
        Event::listen(InvoicePaid::class, fn (InvoicePaid $e) => app(ClientMailer::class)->send($e->invoice->client, 'payment_receipt', invoice: $e->invoice));
    }
}
