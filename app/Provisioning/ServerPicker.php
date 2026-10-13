<?php

namespace App\Provisioning;

use App\Models\Activity;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Chooses the server a new service goes on: an active server of the
 * product's module, from the product's server group if it has one, with room
 * for another account. A group's fill type picks between them; with no group
 * the least used server wins.
 */
class ServerPicker
{
    /** Puts the service on a server unless it has one. Returns why it couldn't, or null. */
    public function assign(Service $service, ProvisioningModule $module): ?string
    {
        if ($service->server_id !== null) {
            return null;
        }

        return DB::transaction(function () use ($service, $module) {
            $server = $this->pick($service->product, $module->key());

            if ($server === null) {
                return $this->noRoom($service->product->serverGroup, $module);
            }

            $service->server()->associate($server);
            $service->save();
            Activity::record("Put {$service->description()} on server {$server->name}", $service);

            return null;
        });
    }

    /** The server the next service of $product goes on, or null when none has room. */
    public function pick(Product $product, string $module): ?Server
    {
        $group = $product->serverGroup;

        // Lock the candidates first, so two services can't both take a server's last slot.
        $ids = Server::active()
            ->where('module', $module)
            ->when($group, fn (Builder $query) => $query->whereHas('groups', fn (Builder $groups) => $groups->whereKey($group->id)))
            ->lockForUpdate()
            ->pluck('id');

        $servers = Server::whereKey($ids)->withAccountCount()->get()->filter->hasRoom();
        $fill = $group?->fill_type === ServerGroup::FILL;

        return $servers
            ->sortBy(fn (Server $server) => $fill
                ? [-$server->usage(), $server->id]
                : [$server->usage(), $server->account_count, $server->id])
            ->first();
    }

    private function noRoom(?ServerGroup $group, ProvisioningModule $module): string
    {
        return $group
            ? "No active {$module->label()} server in the group {$group->name} has room for another account. Add a server to the group or raise a server's maximum accounts, then run create again."
            : "No active {$module->label()} server has room for another account. Add one under Products > Servers, then run create again.";
    }
}
