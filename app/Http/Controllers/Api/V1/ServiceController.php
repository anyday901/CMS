<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Services through the REST API, and the same suspend, unsuspend and terminate actions as the admin area. */
class ServiceController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ServiceStatus::class)],
        ]);

        $services = Service::with('product')
            ->when($filters['client_id'] ?? null, fn ($query, $id) => $query->where('client_id', $id))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ServiceResource::collection($services);
    }

    public function show(Service $service): ServiceResource
    {
        return ServiceResource::make($service->load('product'));
    }

    /** Like the buttons on the admin service page; a service in the wrong state gets a 422. */
    public function action(Service $service, string $action, ServiceLifecycle $lifecycle): ServiceResource
    {
        $allowed = match ($action) {
            'suspend' => $service->status === ServiceStatus::Active,
            'unsuspend' => $service->status === ServiceStatus::Suspended,
            'terminate' => in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending], true),
        };

        abort_unless($allowed, 422, "Can't {$action} a {$service->status->value} service.");

        match ($action) {
            'suspend' => $lifecycle->suspend($service, 'manual'),
            'unsuspend' => $lifecycle->unsuspend($service),
            'terminate' => $lifecycle->terminate($service),
        };

        return ServiceResource::make($service->refresh()->load('product'));
    }
}
