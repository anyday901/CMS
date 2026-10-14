<?php

namespace Tests\Feature\Servers;

use App\Billing\OverageBiller;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\Usage;
use App\Provisioning\UsageCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UsageTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1024 * 1024 * 1024;

    private const SEPTEMBER = '2026-09-01';

    private const OCTOBER = '2026-10-01';

    /** @var array<int, int> each Proxmox VM's traffic counter in bytes */
    private array $traffic = [105 => 0];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PORT)) {
            8006 => $this->proxmox($request),
            2222 => $this->directAdmin($request),
            8443 => $this->plesk(),
            default => $this->whm($request),
        });
    }

    public function test_cpanel_disk_and_bandwidth_are_read_for_the_whole_server_and_shown(): void
    {
        $server = Server::factory()->create(['name' => 'cp1']);
        $service = $this->serviceOn($server, 'cpanel', ['username' => 'example']);
        $other = $this->serviceOn($server, 'cpanel', ['username' => 'gone']);

        $result = app(UsageCollector::class)->collect(now());

        $this->assertSame(['services' => 1, 'errors' => []], $result);
        $usage = $service->currentUsage()->first();
        $this->assertSame([1500, 2000, 3072, 10240], [$usage->disk_mb, $usage->disk_limit_mb, $usage->bandwidth_mb, $usage->bandwidth_limit_mb]);
        $this->assertNull($other->currentUsage()->first());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'json-api/showbw') && $request['month'] === now()->month);

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/services/{$service->id}")->assertSee('Usage')->assertSee('1.5 GB')->assertSee('of 2 GB')->assertSee('3 GB')->assertSee('of 10 GB');
        $this->actingAs($service->client, 'client');
        $this->get("/portal/services/{$service->id}")->assertSee('Usage')->assertSee('Bandwidth in '.now()->format('F'));
    }

    public function test_a_server_that_cant_be_read_is_reported_and_the_others_still_are(): void
    {
        $broken = Server::factory()->create(['name' => 'broken', 'hostname' => 'broken.example.test']);
        $this->serviceOn($broken, 'cpanel', ['username' => 'example']);
        $fine = Server::factory()->create(['name' => 'fine']);
        $this->serviceOn($fine, 'cpanel', ['username' => 'example']);

        $result = app(UsageCollector::class)->collect(now());

        $this->assertSame(1, $result['services']);
        $this->assertSame(['broken: WHM: Access denied'], $result['errors']);
        $this->artisan('usage:collect')->expectsOutput('Read the disk and bandwidth use of 1 service(s).')->expectsOutput("Couldn't read usage from broken: WHM: Access denied");
    }

    public function test_proxmox_traffic_counters_add_up_over_the_month_and_survive_restarts(): void
    {
        $server = Server::factory()->create(['module' => 'proxmox', 'hostname' => 'pve.example.test', 'username' => 'root@pam!billing']);
        $service = $this->serviceOn($server, 'proxmox', ['provisioning_data' => ['vmid' => 105]]);
        $collector = app(UsageCollector::class);
        $day = CarbonImmutable::parse('2026-09-10 12:00');

        // The first reading only sets where the counter starts.
        $this->traffic[105] = 4 * self::GB;
        $collector->collect($day);
        $this->assertSame(0, $service->currentUsage()->first()->bandwidth_mb);

        $this->traffic[105] = 5 * self::GB;
        $collector->collect($day->addHour());
        $this->assertSame(1024, $service->currentUsage()->first()->bandwidth_mb);

        // The VM restarted, so its counter began again.
        $this->traffic[105] = self::GB / 2;
        $collector->collect($day->addHours(2));
        $usage = $service->currentUsage()->first();
        $this->assertSame([1536, 10240, 20480], [$usage->bandwidth_mb, $usage->disk_mb, $usage->disk_limit_mb]);

        // A new month starts from the last counter of the one before.
        $this->traffic[105] = self::GB;
        $collector->collect(CarbonImmutable::parse('2026-10-01 00:50'));
        $this->assertSame(512, $service->usages()->whereDate('period', self::OCTOBER)->sole()->bandwidth_mb);
        $this->assertSame(1536, $service->usages()->whereDate('period', self::SEPTEMBER)->sole()->bandwidth_mb);
    }

    public function test_directadmin_and_plesk_usage_are_read(): void
    {
        $da = Server::factory()->create(['module' => 'directadmin', 'hostname' => 'da.example.test', 'username' => 'admin', 'password' => 'pass']);
        $daService = $this->serviceOn($da, 'directadmin', ['username' => 'bob']);
        $plesk = Server::factory()->create(['module' => 'plesk', 'hostname' => 'plesk.example.test', 'username' => 'admin', 'api_token' => 'key']);
        $pleskService = $this->serviceOn($plesk, 'plesk', ['username' => 'ann', 'label' => 'Example.org']);

        app(UsageCollector::class)->collect(now());

        $da = $daService->currentUsage()->first();
        $this->assertSame([250, 1000, 1201, null], [$da->disk_mb, $da->disk_limit_mb, $da->bandwidth_mb, $da->bandwidth_limit_mb]);
        $plesk = $pleskService->currentUsage()->first();
        $this->assertSame([300, 5120, 2048, null], [$plesk->disk_mb, $plesk->disk_limit_mb, $plesk->bandwidth_mb, $plesk->bandwidth_limit_mb]);
    }

    public function test_overages_are_invoiced_once_after_the_month(): void
    {
        $product = Product::factory()->create(['name' => 'Starter', 'module' => 'cpanel', 'overage_billing' => true, 'overage_disk_mb' => 1024, 'overage_disk_price' => 100, 'overage_bandwidth_mb' => 10240, 'overage_bandwidth_price' => 50]);
        $service = Service::factory()->for($product)->create(['label' => 'example.com']);
        $september = $service->usages()->create(['period' => self::SEPTEMBER, 'disk_mb' => 3072, 'bandwidth_mb' => 15360]);
        $october = $service->usages()->create(['period' => self::OCTOBER, 'disk_mb' => 9000, 'bandwidth_mb' => 99999]);
        // Within its allowance, and an ended service: nothing to bill.
        $small = Service::factory()->for($product)->for($service->client)->create();
        $small->usages()->create(['period' => self::SEPTEMBER, 'disk_mb' => 100, 'bandwidth_mb' => 100]);
        $ended = Service::factory()->for($product)->create(['status' => ServiceStatus::Terminated]);
        $ended->usages()->create(['period' => self::SEPTEMBER, 'disk_mb' => 5000]);
        // Overage prices are in the billing currency only.
        $euros = Service::factory()->for($product)->for(Client::factory()->create(['currency' => 'EUR']))->create();
        $euros->usages()->create(['period' => self::SEPTEMBER, 'disk_mb' => 5000]);

        $invoices = app(OverageBiller::class)->run(CarbonImmutable::parse(self::OCTOBER));

        $this->assertCount(1, $invoices);
        $invoice = $invoices->first();
        $this->assertSame([$service->client_id, 450, '2026-10-08'], [$invoice->client_id, $invoice->subtotal, $invoice->due_date->toDateString()]);
        $this->assertSame([
            ['Disk overage for Starter - example.com, September 2026: 2 GB over the 1 GB included, at $1.00 per GB', 200],
            ['Bandwidth overage for Starter - example.com, September 2026: 5 GB over the 10 GB included, at $0.50 per GB', 250],
        ], $invoice->items->map(fn (InvoiceItem $item) => [$item->description, $item->amount])->all());
        $this->assertSame(InvoiceItem::TYPE_OVERAGE, $invoice->items->first()->type);
        $this->assertSame($invoice->id, $september->fresh()->invoice_id);
        $this->assertNotNull($ended->usages()->first()->billed_at);
        $this->assertNull($ended->usages()->first()->invoice_id);
        $this->assertNotNull($euros->usages()->first()->billed_at);
        $this->assertNull($euros->usages()->first()->invoice_id);
        $this->assertNull($october->fresh()->billed_at);

        $this->assertCount(0, app(OverageBiller::class)->run(CarbonImmutable::parse('2026-10-02')));
    }

    public function test_billing_run_bills_overages_and_products_save_their_usage_billing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:05'));
        $product = Product::factory()->create(['module' => 'cpanel', 'overage_billing' => true, 'overage_disk_mb' => 1024, 'overage_disk_price' => 100]);
        Service::factory()->for($product)->create(['next_due_date' => '2026-12-01'])->usages()->create(['period' => self::SEPTEMBER, 'disk_mb' => 2048]);

        $this->artisan('billing:run')->expectsOutput('Generated 1 overage invoice(s).');

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/products/{$product->id}/edit")->assertSee('Usage billing');
        $this->put("/admin/products/{$product->id}", [
            'name' => 'Starter', 'active' => '1', 'module' => 'cpanel', 'module_config' => ['cpanel' => ['package' => 'basic']],
            'overage_billing' => '1', 'overage_disk_mb' => '2048', 'overage_disk_price' => '0.75', 'overage_bandwidth_mb' => '', 'overage_bandwidth_price' => '',
        ])->assertSessionHasNoErrors();
        $product->refresh();
        $this->assertSame([true, 2048, 75, null, 0], [$product->overage_billing, $product->overage_disk_mb, $product->overage_disk_price, $product->overage_bandwidth_mb, $product->overage_bandwidth_price]);
    }

    public function test_sizes_are_read_from_panel_text(): void
    {
        $this->assertSame([1500, 2560, 1024, null, null, null], [Usage::parse('1500M'), Usage::parse('2.5G'), Usage::parse('1024'), Usage::parse('unlimited'), Usage::parse('0'), Usage::parse(null)]);
        $this->assertSame([3072, null], [Usage::fromBytes(3 * self::GB), Usage::fromBytes(-1)]);
    }

    private function serviceOn(Server $server, string $module, array $account): Service
    {
        $product = Product::factory()->create(['module' => $module]);
        $service = Service::factory()->for($product)->create(['label' => $account['label'] ?? 'example.com']);
        $service->forceFill(['server_id' => $server->id, 'provisioned_at' => now(), ...collect($account)->except('label')->all()])->save();

        return $service;
    }

    private function whm(Request $request): mixed
    {
        if (str_contains($request->url(), 'broken.example.test')) {
            return Http::response(['metadata' => ['result' => 0, 'reason' => 'Access denied']]);
        }

        $function = str($request->url())->after('json-api/')->before('?')->value();

        return Http::response(['metadata' => ['result' => 1], 'data' => match ($function) {
            'listaccts' => ['acct' => [['user' => 'example', 'diskused' => '1500M', 'disklimit' => '2000M'], ['user' => 'other', 'diskused' => '1M', 'disklimit' => 'unlimited']]],
            'showbw' => ['acct' => [['user' => 'example', 'totalbytes' => (string) (3 * self::GB), 'limit' => (string) (10 * self::GB)]]],
            default => [],
        }]);
    }

    private function proxmox(Request $request): mixed
    {
        return Http::response(['data' => str_contains($request->url(), 'cluster/resources')
            ? collect($this->traffic)->map(fn (int $bytes, int $vmid) => ['vmid' => $vmid, 'type' => 'lxc', 'disk' => 10 * self::GB, 'maxdisk' => 20 * self::GB, 'netin' => intdiv($bytes, 4), 'netout' => $bytes - intdiv($bytes, 4)])->values()->all()
            : null]);
    }

    private function directAdmin(Request $request): mixed
    {
        return Http::response(match (str($request->url())->after(':2222/')->before('?')->value()) {
            'CMD_API_SHOW_USER_USAGE' => 'bandwidth=1200.5&quota=250.2&domains=1',
            'CMD_API_SHOW_USER_CONFIG' => 'bandwidth=unlimited&quota=1000&package=basic',
            default => 'error=1&text=Unknown',
        });
    }

    private function plesk(): mixed
    {
        $mb = 1024 * 1024;

        return Http::response('<?xml version="1.0"?><packet><webspace><get><result><status>ok</status><filter-id>example.org</filter-id><id>7</id><data>'
            .'<gen_info><name>example.org</name><real_size>'.(300 * $mb).'</real_size></gen_info>'
            .'<stat><traffic>'.(2048 * $mb).'</traffic></stat>'
            .'<limits><limit><name>disk_space</name><value>'.(5120 * $mb).'</value></limit><limit><name>max_traffic</name><value>-1</value></limit></limits>'
            .'</data></result></get></webspace></packet>');
    }
}
