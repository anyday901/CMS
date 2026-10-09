<?php

namespace App\Billing;

use App\Enums\ServiceStatus;
use App\Events\ServiceActivated;
use App\Events\ServiceSuspended;
use App\Events\ServiceTerminated;
use App\Events\ServiceUnsuspended;
use App\Models\Service;

/**
 * Status changes for services. Each change fires an event so provisioning
 * modules can act on it without the billing core knowing about them.
 */
class ServiceLifecycle
{
    public const REASON_OVERDUE = 'overdue';

    public function activate(Service $service): void
    {
        $service->status = ServiceStatus::Active;
        $service->save();

        ServiceActivated::dispatch($service);
    }

    public function suspend(Service $service, string $reason): void
    {
        $service->forceFill([
            'status' => ServiceStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ])->save();

        ServiceSuspended::dispatch($service);
    }

    public function unsuspend(Service $service): void
    {
        $service->forceFill([
            'status' => ServiceStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        ServiceUnsuspended::dispatch($service);
    }

    public function terminate(Service $service): void
    {
        $service->forceFill([
            'status' => ServiceStatus::Terminated,
            'terminated_at' => now(),
        ])->save();

        ServiceTerminated::dispatch($service);
    }
}
