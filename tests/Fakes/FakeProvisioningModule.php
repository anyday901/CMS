<?php

namespace Tests\Fakes;

use App\Models\Service;
use App\Provisioning\ProvisioningModule;
use App\Provisioning\ProvisioningResult;

/** Records the actions it is asked to run; can be told to fail. */
class FakeProvisioningModule implements ProvisioningModule
{
    /** @var list<array{string, int}> */
    public static array $calls = [];

    public static ?string $failWith = null;

    public static bool $throw = false;

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake Panel';
    }

    public function configFields(): array
    {
        return [
            'server' => ['label' => 'Server', 'required' => true],
            'plan' => ['label' => 'Plan', 'help' => 'Package name on the server'],
        ];
    }

    public function create(Service $service): ProvisioningResult
    {
        return $this->run('create', $service, ['remote_id' => 'vm-'.$service->id]);
    }

    public function suspend(Service $service): ProvisioningResult
    {
        return $this->run('suspend', $service);
    }

    public function unsuspend(Service $service): ProvisioningResult
    {
        return $this->run('unsuspend', $service);
    }

    public function terminate(Service $service): ProvisioningResult
    {
        return $this->run('terminate', $service);
    }

    private function run(string $action, Service $service, array $data = []): ProvisioningResult
    {
        self::$calls[] = [$action, $service->id];

        if (self::$throw) {
            throw new FakeModuleUnreachable('Connection refused');
        }

        return self::$failWith ? ProvisioningResult::failed(self::$failWith) : ProvisioningResult::ok(null, $data);
    }
}
