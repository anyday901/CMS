<?php

namespace App\Providers;

use App\Enums\StaffRole;
use App\Events\ServiceActivated;
use App\Events\ServiceSuspended;
use App\Events\ServiceTerminated;
use App\Events\ServiceUnsuspended;
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
            Gate::define($ability, fn (User $user) => $user->role->allows($ability));
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
    }
}
