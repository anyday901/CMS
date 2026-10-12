<?php

namespace App\Billing;

use App\Enums\ServiceStatus;
use App\Events\ServiceActivated;
use App\Events\ServiceSuspended;
use App\Events\ServiceTerminated;
use App\Events\ServiceUnsuspended;
use App\Models\Activity;
use App\Models\Service;

/**
 * Status changes for services. Each change fires an event so provisioning
 * modules can act on it without the billing core knowing about them. A
 * service's add-ons are suspended, unsuspended and terminated with it.
 */
class ServiceLifecycle
{
    public const REASON_OVERDUE = 'overdue';

    public function activate(Service $service): void
    {
        $service->status = ServiceStatus::Active;
        $service->save();
        Activity::record("Activated {$service->description()}", $service);

        ServiceActivated::dispatch($service);
    }

    public function suspend(Service $service, string $reason): void
    {
        $service->forceFill([
            'status' => ServiceStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ])->save();
        Activity::record("Suspended {$service->description()} ({$reason})", $service);

        ServiceSuspended::dispatch($service);

        foreach ($service->addons()->where('status', ServiceStatus::Active)->get() as $addon) {
            $this->suspend($addon, $reason);
        }
    }

    public function unsuspend(Service $service): void
    {
        $reason = $service->suspension_reason;
        $service->forceFill([
            'status' => ServiceStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();
        Activity::record("Unsuspended {$service->description()}", $service);

        ServiceUnsuspended::dispatch($service);

        // Only add-ons suspended for the same reason, so one suspended on its own stays so.
        foreach ($service->addons()->where('status', ServiceStatus::Suspended)->where('suspension_reason', $reason)->get() as $addon) {
            $this->unsuspend($addon);
        }
    }

    public function terminate(Service $service): void
    {
        $service->forceFill([
            'status' => ServiceStatus::Terminated,
            'terminated_at' => now(),
        ])->save();
        Activity::record("Terminated {$service->description()}", $service);

        ServiceTerminated::dispatch($service);

        foreach ($service->addons()->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended])->get() as $addon) {
            $this->terminate($addon);
        }
    }
}
