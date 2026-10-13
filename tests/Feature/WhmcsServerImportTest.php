<?php

namespace Tests\Feature;

use App\Import\Whmcs\ServerImporter;
use App\Import\Whmcs\WhmcsCrypt;
use App\Import\Whmcs\WhmcsImporter;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WhmcsDatabase;
use Tests\TestCase;

class WhmcsServerImportTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionInterface $whmcs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->whmcs = WhmcsDatabase::create();
        WhmcsDatabase::seed($this->whmcs);
    }

    public function test_servers_groups_products_and_logins_come_over_with_their_passwords(): void
    {
        $report = (new WhmcsImporter(null, WhmcsDatabase::HASH))->run($this->whmcs, dryRun: false);

        $this->assertSame(['found' => 2, 'imported' => 1, 'skipped' => 1], $report->counts()['Servers']);
        $this->assertSame(['found' => 1, 'imported' => 1, 'skipped' => 0], $report->counts()['Server groups']);
        $this->assertSame(['found' => 1, 'imported' => 1, 'skipped' => 0], $report->counts()['Service logins']);
        $this->assertStringContainsString('Server "Old box" (interworx) uses a WHMCS module this app doesn\'t have.', $report->toMarkdown());

        $server = Server::sole();
        $this->assertSame(['cp1', 'cpanel', 'cp1.example.test', '203.0.113.5', 'root', WhmcsDatabase::SERVER_PASSWORD, 'ABCDEF', 300, true, true],
            [$server->name, $server->module, $server->hostname, $server->ip_address, $server->username, $server->password, $server->api_token, $server->max_accounts, $server->secure, $server->active]);

        $group = ServerGroup::sole();
        $this->assertSame(['Shared hosting', ServerGroup::FILL, [$server->id]], [$group->name, $group->fill_type, $group->servers->modelKeys()]);

        $product = Product::sole();
        $this->assertSame(['cpanel', ['package' => 'gold'], $group->id], [$product->module, $product->module_config, $product->server_group_id]);

        $service = Service::findOrFail(40);
        $this->assertSame([$server->id, 'ada', WhmcsDatabase::SERVICE_PASSWORD], [$service->server_id, $service->username, $service->password]);
        $this->assertNotNull($service->provisioned_at);

        // The account is set up, so the portal shows how to log in.
        $this->actingAs($service->client, 'client');
        $this->get('/portal/services/40')->assertOk()->assertSee('https://cp1.example.test:2083')->assertSee('ada')->assertSee(WhmcsDatabase::SERVICE_PASSWORD);
    }

    public function test_without_the_hash_logins_come_over_without_passwords(): void
    {
        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $this->assertStringContainsString('No WHMCS encryption hash was given', $report->toMarkdown());
        $this->assertNull(Server::sole()->password);
        $service = Service::findOrFail(40);
        $this->assertSame(['ada', null], [$service->username, $service->password]);
    }

    public function test_a_wrong_hash_leaves_passwords_out_and_says_so(): void
    {
        $report = (new WhmcsImporter(null, 'not-the-hash'))->run($this->whmcs, dryRun: false);

        $this->assertStringContainsString('Some passwords didn\'t decrypt', $report->toMarkdown());
        $this->assertNull(Server::sole()->password);
        $this->assertNull(Service::findOrFail(40)->password);
    }

    public function test_a_server_staff_already_added_is_used_as_it_is(): void
    {
        $existing = Server::factory()->create(['name' => 'Mine', 'module' => 'cpanel', 'hostname' => 'cp1.example.test']);

        (new WhmcsImporter(null, WhmcsDatabase::HASH))->run($this->whmcs, dryRun: false);

        $this->assertSame([$existing->id], Server::pluck('id')->all());
        $this->assertSame('Mine', $existing->fresh()->name);
        $this->assertSame($existing->id, Service::findOrFail(40)->server_id);
    }

    public function test_a_dry_run_counts_servers_and_saves_none(): void
    {
        $report = (new WhmcsImporter(null, WhmcsDatabase::HASH))->run($this->whmcs, dryRun: true);

        $this->assertSame(1, $report->counts()['Servers']['imported']);
        $this->assertSame(0, Server::count());
        $this->assertSame(0, ServerGroup::count());
    }

    public function test_the_walkthrough_and_command_take_the_hash(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin/settings/whmcs-import?step=2')->assertOk()->assertSee('WHMCS encryption hash');

        $this->artisan('whmcs:import', ['--dry-run' => true, '--hash' => WhmcsDatabase::HASH])->assertSuccessful();
        $this->assertSame(0, Server::count());
    }

    public function test_whmcs_module_names_map_to_this_apps_modules(): void
    {
        $this->assertSame(['cpanel', 'plesk', 'directadmin', 'proxmox', 'proxmox', null], array_map(ServerImporter::module(...), ['cpanel', 'Plesk', 'directadmin', 'proxmoxVPS', 'pvewhmcs', 'interworx']));
    }

    public function test_whmcs_encryption_round_trips_long_values(): void
    {
        $crypt = new WhmcsCrypt(WhmcsDatabase::HASH);
        $long = str_repeat('A long password-', 4);

        $this->assertSame($long, $crypt->decrypt($crypt->encrypt($long)));
        $this->assertNull($crypt->decrypt('not base64 !'));
        $this->assertNull((new WhmcsCrypt('other'))->decrypt($crypt->encrypt($long)));
    }
}
