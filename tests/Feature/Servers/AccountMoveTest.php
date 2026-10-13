<?php

namespace Tests\Feature\Servers;

use App\Billing\ServiceLifecycle;
use App\Jobs\RunProvisioningAction;
use App\Models\AccountMove;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\AccountMover;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\ServerPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountMoveTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'example.com';

    private const PASSWORD = 'First-pass1';

    private const CP1 = 'cp1.example.test';

    private const CP2 = 'cp2.example.test';

    private const DA1 = 'da1.example.test';

    private Server $cp1;

    private Server $cp2;

    private Service $service;

    private User $staff;

    /** @var array<string, array<string, string>> accounts on each server: host => [username => domain] */
    private array $accounts = [];

    /** @var array<string, string> WHM functions to fail with a reason, by host and function: "host function" => reason */
    private array $failures = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $product = Product::factory()->create(['name' => 'Starter', 'module' => 'cpanel', 'module_config' => ['package' => 'basic']]);
        $product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 500]);
        $this->cp1 = Server::factory()->create(['name' => 'cp1', 'hostname' => self::CP1]);
        $this->cp2 = Server::factory()->create(['name' => 'cp2', 'hostname' => self::CP2]);
        $this->service = Service::factory()->for($product)->create(['label' => self::DOMAIN, 'next_due_date' => today()->addDays(20)]);
        $this->service->forceFill(['server_id' => $this->cp1->id, 'username' => 'example', 'password' => self::PASSWORD, 'provisioned_at' => now(), 'provisioning_status' => 'done'])->save();
        $this->accounts = [self::CP1 => ['example' => self::DOMAIN], self::CP2 => [], self::DA1 => []];
        $this->staff = User::factory()->create();

        Http::fake(fn (Request $request) => str_contains($request->url(), ':2222/') ? $this->directAdmin($request) : $this->whm($request));
    }

    public function test_staff_move_an_account_to_another_server_then_finish_the_move(): void
    {
        $this->actingAs($this->staff, 'web');
        $this->get($this->page())->assertOk()->assertSee('Move to another server')->assertSee('cp2 ('.self::CP2.', 0 of 200 accounts)', false);

        $this->post($this->page().'/account-moves', ['to_server_id' => $this->cp2->id])
            ->assertSessionHas('status', 'Creating the account on cp2. The old one stays until you finish the move.');

        // The new account has the same login; the old one is still there.
        $service = $this->service->fresh();
        $this->assertSame([$this->cp2->id, 'done', 'example', self::PASSWORD], [$service->server_id, $service->provisioning_status, $service->username, $service->password]);
        $this->assertSame(['example' => self::DOMAIN], $this->accounts[self::CP2]);
        $this->assertSame(['example' => self::DOMAIN], $this->accounts[self::CP1]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), self::CP2) && str_contains($request->url(), 'createacct') && $request['password'] === self::PASSWORD);
        $move = AccountMove::sole();
        $this->assertSame([AccountMove::COPYING, $this->cp1->id, $this->cp2->id], [$move->status, $move->from_server_id, $move->to_server_id]);

        $this->get($this->page())->assertSee('Moving from cp1 to cp2: waiting for the data to be copied')->assertSee('Finish the move: remove the old account')->assertDontSee('Move to another server');
        $this->actingAs($service->client, 'client');
        $this->get("/portal/services/{$service->id}")->assertSee('We\'re moving this service to a new server.', false);

        $this->actingAs($this->staff, 'web');
        $this->post("/admin/account-moves/{$move->id}/finish")->assertSessionHas('status', 'Removing the old account.');

        $this->assertSame([], $this->accounts[self::CP1]);
        $this->assertSame(['example' => self::DOMAIN], $this->accounts[self::CP2]);
        $this->assertSame(AccountMove::DONE, $move->fresh()->status);
        $this->assertNotNull($move->fresh()->finished_at);
        $this->get($this->page())->assertDontSee('Finish the move');
    }

    public function test_with_nothing_to_copy_the_old_account_goes_once_the_new_one_is_ready(): void
    {
        $this->actingAs($this->staff, 'web');

        $this->post($this->page().'/account-moves', ['to_server_id' => $this->cp2->id, 'remove_at_once' => '1']);

        $this->assertSame(AccountMove::DONE, AccountMove::sole()->status);
        $this->assertSame([], $this->accounts[self::CP1]);
        $this->assertSame(['example' => self::DOMAIN], $this->accounts[self::CP2]);
    }

    public function test_a_move_whose_new_account_fails_can_be_cancelled(): void
    {
        $this->failures[self::CP2.' createacct'] = 'The package basic does not exist.';
        $this->actingAs($this->staff, 'web');

        $this->post($this->page().'/account-moves', ['to_server_id' => $this->cp2->id]);

        $move = AccountMove::sole();
        $this->assertSame(AccountMove::CREATING, $move->status);
        $this->assertSame('WHM: The package basic does not exist.', $this->service->fresh()->provisioning_error);
        $this->get($this->page())->assertSee('Cancel the move');

        $this->delete("/admin/account-moves/{$move->id}")->assertSessionHas('status', 'Move cancelled. The service is back on its old account.');

        $service = $this->service->fresh();
        $this->assertSame([$this->cp1->id, 'example', self::PASSWORD, 'done', null], [$service->server_id, $service->username, $service->password, $service->provisioning_status, $service->provisioning_error]);
        $this->assertSame(AccountMove::CANCELLED, $move->fresh()->status);
        $this->assertSame(['example' => self::DOMAIN], $this->accounts[self::CP1]);
    }

    public function test_removing_the_old_account_can_fail_and_be_tried_again(): void
    {
        $this->failures[self::CP1.' removeacct'] = 'Account is locked.';
        $this->actingAs($this->staff, 'web');
        $this->post($this->page().'/account-moves', ['to_server_id' => $this->cp2->id]);
        $move = AccountMove::sole();

        $this->post("/admin/account-moves/{$move->id}/finish");

        $this->assertSame([AccountMove::FAILED, 'WHM: Account is locked.'], [$move->fresh()->status, $move->fresh()->error]);
        $this->get($this->page())->assertSee('WHM: Account is locked.')->assertSee('Try removing the old account again');

        unset($this->failures[self::CP1.' removeacct']);
        $this->post("/admin/account-moves/{$move->id}/finish");

        $this->assertSame(AccountMove::DONE, $move->fresh()->status);
        $this->assertSame([], $this->accounts[self::CP1]);
    }

    public function test_a_move_needs_another_active_server_of_the_module_with_room(): void
    {
        $this->actingAs($this->staff, 'web');
        $this->cp2->update(['active' => false]);
        $plesk = Server::factory()->create(['module' => 'plesk']);

        $this->post($this->page().'/account-moves', ['to_server_id' => $this->cp2->id])->assertSessionHasErrors(['move_server' => 'cp2 is inactive.']);
        $this->post($this->page().'/account-moves', ['to_server_id' => $this->cp1->id])->assertSessionHasErrors(['move_server' => 'The service is already on that server.']);
        $this->post($this->page().'/account-moves', ['to_server_id' => $plesk->id])->assertSessionHasErrors(['move_server' => "{$plesk->name} isn't a server of this service's module."]);

        $this->cp2->update(['active' => true, 'max_accounts' => 0]);
        $this->post($this->page().'/account-moves', ['to_server_id' => $this->cp2->id])->assertSessionHasErrors(['move_server' => 'cp2 has no room for another account.']);
        $this->assertSame(0, AccountMove::count());
    }

    public function test_a_suspended_service_stays_suspended_on_its_new_server(): void
    {
        app(ServiceLifecycle::class)->suspend($this->service, 'overdue');
        $this->actingAs($this->staff, 'web');

        $this->post($this->page().'/account-moves', ['to_server_id' => $this->cp2->id]);

        // Queued behind create; on a real queue it runs once create lets go of the service.
        $service = $this->service->fresh();
        $this->assertSame(['suspend', 'pending'], [$service->provisioning_action, $service->provisioning_status]);
        (new RunProvisioningAction($service, 'suspend', $service->provisioning_queued))->handle(app(ModuleRegistry::class), app(ServerPicker::class));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), self::CP2.':2087/json-api/suspendacct') && $request['user'] === 'example');
        $this->assertSame('done', $service->fresh()->provisioning_status);
    }

    public function test_ending_the_service_mid_move_removes_the_old_account_too(): void
    {
        $this->actingAs($this->staff, 'web');
        $this->post($this->page().'/account-moves', ['to_server_id' => $this->cp2->id]);

        app(ServiceLifecycle::class)->terminate($this->service->fresh());

        $this->assertSame([[], []], [$this->accounts[self::CP1], $this->accounts[self::CP2]]);
        $this->assertSame(AccountMove::DONE, AccountMove::sole()->status);
    }

    public function test_an_upgrade_to_a_product_of_another_module_moves_the_account(): void
    {
        $da = Server::factory()->create(['name' => 'da1', 'module' => 'directadmin', 'hostname' => self::DA1, 'username' => 'admin', 'password' => 'da-pass', 'ip_address' => '203.0.113.9']);
        $product = Product::factory()->create(['name' => 'DirectAdmin Pro', 'module' => 'directadmin', 'module_config' => ['package' => 'pro']]);
        $product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 900]);
        $this->actingAs($this->staff, 'web');

        $this->get($this->page()."/move?product_id={$product->id}&billing_cycle=monthly&charge=0")->assertOk()
            ->assertSee('DirectAdmin Pro uses another module, so this moves the account');
        $this->post($this->page().'/move', ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'charge' => '0']);

        $service = $this->service->fresh();
        $this->assertSame([$product->id, $da->id, 'example', self::PASSWORD, 'done'], [$service->product_id, $service->server_id, $service->username, $service->password, $service->provisioning_status]);
        $this->assertSame(['example' => self::DOMAIN], $this->accounts[self::DA1]);
        $move = AccountMove::sole();
        $this->assertSame([AccountMove::COPYING, $this->cp1->id, $product->id, 'cpanel'], [$move->status, $move->from_server_id, $move->to_product_id, $move->from_module]);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'changepackage'));
        $this->get($this->page())->assertSee('Moving from cp1 to da1 (was Starter)')->assertDontSee('Cancel the move');

        $this->post("/admin/account-moves/{$move->id}/finish");

        $this->assertSame([], $this->accounts[self::CP1]);
        $this->assertSame(AccountMove::DONE, $move->fresh()->status);
    }

    public function test_a_move_to_a_product_without_a_module_can_still_be_finished_and_blocks_other_moves(): void
    {
        $plain = Product::factory()->create(['name' => 'Managed by hand', 'module' => null]);
        $plain->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 900]);
        $this->actingAs($this->staff, 'web');

        $this->post($this->page().'/move', ['product_id' => $plain->id, 'billing_cycle' => 'monthly', 'charge' => '0']);

        $move = AccountMove::sole();
        $this->assertSame(AccountMove::COPYING, $move->status);
        $this->get($this->page())->assertSee('Finish the move: remove the old account');
        $this->get($this->page().'/move')->assertSee('This service&#039;s account is still moving. Finish or cancel that move first.', false);

        $this->post("/admin/account-moves/{$move->id}/finish");
        $this->assertSame([[], AccountMove::DONE], [$this->accounts[self::CP1], $move->fresh()->status]);
    }

    public function test_a_proxmox_vm_becoming_a_container_is_a_new_account(): void
    {
        $vm = Product::factory()->create(['module' => 'proxmox', 'module_config' => ['template' => 9000]]);
        $container = Product::factory()->create(['module' => 'proxmox', 'module_config' => ['type' => 'container']]);
        $biggerVm = Product::factory()->create(['module' => 'proxmox', 'module_config' => ['template' => 9000, 'cores' => 4]]);
        $mover = app(AccountMover::class);

        $this->assertTrue($mover->migrates($vm, $container));
        $this->assertTrue($mover->migrates($container, $vm));
        $this->assertFalse($mover->migrates($vm, $biggerVm));
        $this->assertFalse($mover->migrates($this->service->product, $this->service->product));
    }

    public function test_clients_see_upgrades_to_other_modules_and_what_they_mean(): void
    {
        $plesk = Product::factory()->create(['name' => 'Plesk Web', 'module' => 'plesk', 'module_config' => ['plan' => 'Unlimited']]);
        $plesk->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 700]);
        $this->service->product->upgrades()->attach($plesk);
        $this->actingAs($this->service->client, 'client');

        $this->get("/portal/services/{$this->service->id}/upgrade")->assertOk()->assertSee('Plesk Web');
        $this->get("/portal/services/{$this->service->id}/upgrade/{$plesk->id}")->assertOk()
            ->assertSee('Plesk Web runs on a different kind of server, so you get a new account there.');
    }

    private function page(): string
    {
        return "/admin/services/{$this->service->id}";
    }

    /** A WHM that keeps each server's accounts. */
    private function whm(Request $request): mixed
    {
        $host = parse_url($request->url(), PHP_URL_HOST);
        $function = str($request->url())->after('json-api/')->before('?')->value();
        $ok = ['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => []];

        if (isset($this->failures["{$host} {$function}"])) {
            return Http::response(['metadata' => ['result' => 0, 'reason' => $this->failures["{$host} {$function}"]]]);
        }

        return Http::response(match ($function) {
            'listaccts' => ['metadata' => ['result' => 1], 'data' => ['acct' => collect($this->accounts[$host])->map(fn ($domain, $user) => ['user' => $user, 'domain' => $domain])->values()->all()]],
            'createacct' => tap($ok, fn () => $this->accounts[$host][$request['username']] = $request['domain']),
            'removeacct' => tap($ok, function () use ($host, $request) {
                unset($this->accounts[$host][$request['username']]);
            }),
            default => $ok,
        });
    }

    /** A DirectAdmin that keeps its users. */
    private function directAdmin(Request $request): mixed
    {
        $command = str($request->url())->after(':2222/')->before('?')->value();

        return Http::response(match ($command) {
            'CMD_API_SHOW_USERS' => http_build_query(['list' => array_keys($this->accounts[self::DA1])]),
            'CMD_API_ACCOUNT_USER' => tap('error=0&text=Created', fn () => $this->accounts[self::DA1][$request['username']] = $request['domain']),
            default => 'error=0',
        });
    }
}
