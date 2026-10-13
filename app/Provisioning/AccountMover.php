<?php

namespace App\Provisioning;

use App\Enums\ServiceStatus;
use App\Jobs\RemoveOldAccount;
use App\Models\AccountMove;
use App\Models\Activity;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves a service's account: to another server of its module, or, after an
 * upgrade or downgrade to a product of another module, to a server of that
 * one. The new account is made first, with the same domain and password;
 * the old one stays until staff have copied the data and finish the move,
 * which removes it. With nothing to copy, the old one can go at once.
 */
class AccountMover
{
    public function __construct(private ModuleRegistry $modules, private Provisioner $provisioner) {}

    /** Why staff can't move this service to another server now, or null when they can. */
    public function problem(Service $service): ?string
    {
        return match (true) {
            ! $this->modules->find($service->product->module) instanceof UsesServers => 'This service\'s product doesn\'t use servers.',
            ! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true) => 'Only active or suspended services can be moved.',
            $service->server_id === null || $service->provisioned_at === null => 'The service has no account on a server yet.',
            $service->provisioning_status === Provisioner::PENDING => 'Wait for the module\'s last action to finish.',
            $service->openMove()->exists() => 'The service is already moving.',
            default => null,
        };
    }

    /**
     * Starts moving the account to $server, another active server of its
     * module: queues create there, keeping the old account until the move
     * is finished (or as soon as the new one is ready, with $removeAtOnce).
     *
     * @throws InvalidArgumentException when the move isn't allowed
     */
    public function toServer(Service $service, Server $server, bool $removeAtOnce, ?User $staff): AccountMove
    {
        $move = DB::transaction(function () use ($service, $server, $removeAtOnce, $staff) {
            $locked = Service::lockForUpdate()->with('product')->findOrFail($service->id);
            $problem = $this->problem($locked) ?? match (true) {
                $server->module !== $locked->product->module => "{$server->name} isn't a server of this service's module.",
                $server->id === $locked->server_id => 'The service is already on that server.',
                ! $server->active => "{$server->name} is inactive.",
                ! Server::whereKey($server->id)->withAccountCount()->first()->hasRoom() => "{$server->name} has no room for another account.",
                default => null,
            };

            if ($problem !== null) {
                throw new InvalidArgumentException($problem);
            }

            $move = $this->begin($locked, $locked->product, $removeAtOnce, $staff);
            $move->update(['to_server_id' => $server->id]);
            // The login stays the same, so the client keeps their username and password.
            $locked->forceFill(['server_id' => $server->id, 'provisioning_data' => null, 'provisioned_at' => null])->save();
            Activity::record("Started moving {$locked->description()} from {$move->fromServer?->name} to {$server->name}", $locked);

            return $move;
        });

        $this->provisioner->queue($service->refresh(), 'create');

        return $move;
    }

    /** Whether moving between these products means moving the account, rather than changing its package. */
    public function migrates(Product $from, Product $to): bool
    {
        if ($from->module === $to->module) {
            $module = $this->modules->find($from->module);

            return $module instanceof SplitsAccountKinds && $module->needsNewAccount($from, $to);
        }

        return $this->modules->find($from->module) instanceof UsesServers || $this->modules->find($to->module) instanceof UsesServers;
    }

    /** Starts moving the account when $product needs a new one, and returns whether it does. */
    public function beginIfNeeded(Service $service, Product $product, ?User $staff): bool
    {
        if (! $this->migrates($service->product, $product)) {
            return false;
        }

        $this->beginMigration($service, $product, $staff);

        return true;
    }

    /**
     * Records the old account of a service moving to $product of another
     * module, with the service locked and still describing the old account,
     * and clears the service's login so the new module makes its own (the
     * password stays). Call queue() once the change is saved.
     */
    public function beginMigration(Service $service, Product $product, ?User $staff): AccountMove
    {
        $move = $this->begin($service, $service->product, false, $staff);
        $move->update(['to_product_id' => $product->id]);
        $service->forceFill(['server_id' => null, 'username' => null, 'provisioning_data' => null, 'provisioned_at' => null]);

        return $move;
    }

    /** Queues create for the new account of a migration started with beginMigration(). */
    public function queue(Service $service): void
    {
        $service = $service->fresh(['product']);

        if ($this->modules->find($service->product->module) === null) {
            $this->created($service); // No module makes the new account, so it is ready.

            return;
        }

        $this->provisioner->queue($service, 'create');
    }

    /**
     * Called once create has made the new account: the move waits for the
     * data to be copied, or goes on to remove the old account. A suspended
     * service's new account is suspended too.
     */
    public function created(Service $service): void
    {
        $move = $service->openMove()->first();

        if ($move === null || $move->status !== AccountMove::CREATING) {
            return;
        }

        if ($service->status === ServiceStatus::Suspended) {
            $this->provisioner->queue($service, 'suspend');
        }

        if (! $move->remove_old || $move->remove_at_once) {
            $this->finish($move);

            return;
        }

        $move->update(['status' => AccountMove::COPYING]);
        Activity::record("The new account of {$service->description()} is ready; copy the data, then finish the move", $service);
        StaffAlert::send(
            'manage-clients',
            "Ready to copy: {$service->description()}",
            [
                "The new account of {$service->description()} ({$service->client->fullName()}) is ready".($service->server ? " on {$service->server->name}" : '').'.',
                'Copy the data over from the old account, then finish the move on the service page to remove the old one.',
            ],
            'Open the service',
            route('admin.services.show', $service),
        );
    }

    /** Removes the old account (or marks the move done when there is none to remove). */
    public function finish(AccountMove $move): void
    {
        if (! $move->remove_old) {
            $this->done($move, 'Moved '.$move->service->description().'; there was no old account to remove.');

            return;
        }

        $move->update(['status' => AccountMove::REMOVING, 'error' => null]);
        RemoveOldAccount::dispatch($move);
    }

    /**
     * Gives up a move whose new account isn't ready, putting the old details
     * back on the service. Not for a move with a new product, which has
     * already been billed.
     *
     * @throws InvalidArgumentException
     */
    public function cancel(AccountMove $move): void
    {
        if ($move->status !== AccountMove::CREATING || $move->changesProduct()) {
            throw new InvalidArgumentException('Only a move to another server whose new account isn\'t ready can be cancelled.');
        }

        if ($move->service->provisioning_status === Provisioner::PENDING) {
            throw new InvalidArgumentException('Wait for create to finish or fail on the new server first.');
        }

        DB::transaction(function () use ($move) {
            $move->service->forceFill([
                'server_id' => $move->from_server_id,
                'label' => $move->from_label,
                'username' => $move->from_username,
                'password' => $move->from_password,
                'provisioning_data' => $move->from_data,
                'provisioned_at' => now(),
                'provisioning_status' => Provisioner::DONE,
                'provisioning_error' => null,
            ])->save();
            $move->update(['status' => AccountMove::CANCELLED, 'finished_at' => now()]);
            Activity::record("Cancelled the move of {$move->service->description()}", $move->service);
        });
    }

    /** Called when the service ends: its old account goes as well. */
    public function serviceEnded(Service $service): void
    {
        $move = $service->openMove()->first();

        if ($move !== null && $move->status !== AccountMove::REMOVING) {
            $this->finish($move);
        }
    }

    public function done(AccountMove $move, string $message): void
    {
        $move->update(['status' => AccountMove::DONE, 'error' => null, 'finished_at' => now()]);
        Activity::record($message, $move->service);
    }

    private function begin(Service $service, Product $from, bool $removeAtOnce, ?User $staff): AccountMove
    {
        return $service->accountMoves()->create([
            'status' => AccountMove::CREATING,
            'from_product_id' => $from->id,
            'from_server_id' => $service->server_id,
            'from_module' => $from->module,
            'from_label' => $service->label,
            'from_username' => $service->username,
            'from_password' => $service->password,
            'from_data' => $service->provisioning_data,
            // Nothing was made by a module that never ran.
            'remove_old' => $this->modules->find($from->module) !== null && $service->provisioned_at !== null,
            'remove_at_once' => $removeAtOnce,
            'requested_by' => $staff?->id,
        ]);
    }
}
