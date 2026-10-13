<?php

namespace Tests\Feature\Servers;

use App\Enums\ServiceStatus;
use App\Enums\StaffRole;
use App\Jobs\RunProvisioningAction;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Ordering\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServiceServerTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'example.com';

    private const PASSWORD = 'Another-pass1';

    private const OLD_PASSWORD = 'First-pass1';

    private const NEW_DOMAIN = 'example.org';

    private Product $product;

    private Server $server;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = Product::factory()->create(['name' => 'Starter', 'module' => 'cpanel', 'module_config' => ['package' => 'basic']]);
        $this->product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 500]);
        $this->server = Server::factory()->create(['name' => 'cp1', 'hostname' => 'cp1.example.test']);
        $this->service = Service::factory()->for($this->product)->create(['label' => self::DOMAIN]);
        $this->service->forceFill(['server_id' => $this->server->id, 'username' => 'example', 'password' => self::OLD_PASSWORD, 'provisioned_at' => now()])->save();
    }

    public function test_staff_change_the_login_and_a_new_password_is_set_on_the_server(): void
    {
        Queue::fake();
        $other = Server::factory()->create(['name' => 'cp2']);
        $this->actingAs(User::factory()->create(['role' => StaffRole::Billing]), 'web');

        $this->get("/admin/services/{$this->service->id}")->assertOk()->assertSee('cp1')->assertSee('example')->assertSee(self::OLD_PASSWORD);

        $this->put("/admin/services/{$this->service->id}/server", ['server_id' => $other->id, 'label' => self::NEW_DOMAIN, 'username' => 'exampl2', 'password' => self::PASSWORD])
            ->assertSessionHas('status', 'Saved. The new password is being set on the server.');

        // The working password stays until the server has taken the new one.
        $service = $this->service->fresh();
        $this->assertSame([$other->id, self::NEW_DOMAIN, 'exampl2', self::OLD_PASSWORD, self::PASSWORD], [$service->server_id, $service->label, $service->username, $service->password, $service->new_password]);
        $this->get("/admin/services/{$service->id}")->assertSee('A new password is waiting to be set on the server');
        Queue::assertPushed(RunProvisioningAction::class, fn (RunProvisioningAction $job) => $job->action === 'change_password');
        $this->assertSame(['label', 'server_id', 'username', 'new_password'], Activity::where('description', 'like', 'Changed the server details%')->sole()->properties['changed']);
    }

    public function test_the_new_password_replaces_the_old_one_once_the_server_takes_it(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['metadata' => ['result' => 0, 'reason' => 'The password is too weak.']])
            ->push(['metadata' => ['result' => 1], 'data' => []])]);
        $this->actingAs(User::factory()->create(), 'web');

        $this->put("/admin/services/{$this->service->id}/server", ['server_id' => $this->server->id, 'label' => self::DOMAIN, 'username' => 'example', 'password' => self::PASSWORD]);

        $service = $this->service->fresh();
        $this->assertSame('WHM: The password is too weak.', $service->provisioning_error);
        $this->assertSame([self::OLD_PASSWORD, self::PASSWORD], [$service->password, $service->new_password]);

        $this->post("/admin/services/{$service->id}/provision", ['action' => 'change_password']);

        $service = $service->fresh();
        $this->assertSame('done', $service->provisioning_status);
        $this->assertSame([self::PASSWORD, null], [$service->password, $service->new_password]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'json-api/passwd') && $request['password'] === self::PASSWORD);
    }

    public function test_before_the_account_exists_create_uses_the_new_password(): void
    {
        Queue::fake();
        $this->service->forceFill(['provisioned_at' => null, 'status' => ServiceStatus::Pending])->save();
        $this->actingAs(User::factory()->create(), 'web');

        $this->put("/admin/services/{$this->service->id}/server", ['server_id' => $this->server->id, 'label' => self::DOMAIN, 'password' => self::PASSWORD])
            ->assertSessionHas('status', 'Saved.');

        Queue::assertNothingPushed();
        $this->assertSame(self::PASSWORD, $this->service->fresh()->password);
    }

    public function test_server_details_are_checked(): void
    {
        $proxmox = Server::factory()->create(['module' => 'proxmox']);
        $this->actingAs(User::factory()->create(), 'web');

        $this->put("/admin/services/{$this->service->id}/server", ['server_id' => $proxmox->id, 'label' => 'not a domain', 'username' => 'two words', 'password' => 'short'])
            ->assertSessionHasErrors(['server_id', 'label', 'username', 'password']);
        $this->assertSame($this->server->id, $this->service->fresh()->server_id);
    }

    public function test_staff_who_cannot_manage_clients_only_see_the_login(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');

        $this->get("/admin/services/{$this->service->id}")->assertOk()->assertSee('cp1')->assertDontSee(self::OLD_PASSWORD)->assertDontSee('Change server or login');
        $this->put("/admin/services/{$this->service->id}/server", ['label' => self::NEW_DOMAIN])->assertForbidden();
    }

    public function test_the_store_asks_for_the_domain_of_hosting(): void
    {
        $this->get("/store/order/{$this->product->id}")->assertOk()->assertSee('The domain this service is set up for.');

        $this->post("/store/order/{$this->product->id}", ['billing_cycle' => 'monthly'])
            ->assertSessionHasErrors(['label' => 'Enter the domain name for this service, such as example.com.']);
        $this->post("/store/order/{$this->product->id}", ['billing_cycle' => 'monthly', 'label' => 'my site'])
            ->assertSessionHasErrors(['label' => 'Enter the domain name, such as example.com.']);
        $this->post("/store/order/{$this->product->id}", ['billing_cycle' => 'monthly', 'label' => 'Shop.Example.COM'])->assertRedirect('/cart');

        $this->assertSame('shop.example.com', array_values(app(Cart::class)->lines())[0]['label']);
    }

    public function test_clients_see_how_to_log_in_once_the_account_is_made(): void
    {
        $this->actingAs($this->service->client, 'client');

        $this->get("/portal/services/{$this->service->id}")->assertOk()
            ->assertSee('Logging in')
            ->assertSee('https://cp1.example.test:2083')
            ->assertSee('example')
            ->assertSee(self::OLD_PASSWORD);

        $this->service->forceFill(['provisioned_at' => null, 'status' => ServiceStatus::Pending])->save();
        $this->get("/portal/services/{$this->service->id}")->assertOk()->assertDontSee('Logging in')->assertDontSee(self::OLD_PASSWORD);
    }

    public function test_other_clients_cannot_see_the_login(): void
    {
        $this->actingAs(Client::factory()->create(), 'client');

        $this->get("/portal/services/{$this->service->id}")->assertNotFound();
    }
}
