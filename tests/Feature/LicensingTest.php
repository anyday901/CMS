<?php

namespace Tests\Feature;

use App\Billing\ServiceLifecycle;
use App\Http\Controllers\Api\LicenseCheckController;
use App\Models\License;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LicensingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-long-signing-secret-123';

    private const CHECK = '/api/licenses/check';

    private const DOMAIN = 'shop.example.com';

    private const DIRECTORY = '/home/shop/public_html';

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $product = Product::factory()->create(['name' => 'Shop Pro', 'module' => 'licensing', 'module_config' => ['secret' => self::SECRET, 'prefix' => 'SHOP-', 'lock' => 'all', 'reissue' => 'client']]);
        $this->service = Service::factory()->for($product)->create();
        app(Provisioner::class)->queue($this->service, 'create');
    }

    public function test_create_issues_a_key_and_the_first_check_locks_it_where_it_runs(): void
    {
        $license = $this->service->license()->sole();
        $this->assertMatchesRegularExpression('/^SHOP-([A-HJ-NP-Z2-9]{5}-){3}[A-HJ-NP-Z2-9]{5}$/', $license->key);
        $this->assertSame('done', $this->service->fresh()->provisioning_status);

        $answer = $this->check(['domain' => 'https://www.Shop.example.com/cart', 'directory' => self::DIRECTORY.'/', 'check_token' => 'nonce-1']);

        $answer->assertOk()->assertJson([
            'status' => 'Active', 'message' => null, 'key' => $license->key, 'product' => 'Shop Pro',
            'registered_name' => $this->service->client->fullName(), 'service_status' => 'active', 'next_due_date' => '2026-11-01',
            'domains' => [self::DOMAIN], 'ips' => ['127.0.0.1'], 'directories' => [self::DIRECTORY], 'check_token' => 'nonce-1',
        ]);
        // The software checks the answer is really ours with the shared secret.
        $this->assertSame(hash_hmac('sha256', $answer->getContent(), self::SECRET), $answer->headers->get(LicenseCheckController::SIGNATURE));
        $license->refresh();
        $this->assertSame([1, self::DOMAIN, '127.0.0.1'], [$license->checks, $license->last_domain, $license->last_ip]);

        // The same install keeps working; another domain doesn't.
        $this->check(['domain' => self::DOMAIN, 'directory' => self::DIRECTORY])->assertJson(['status' => 'Active']);
        $this->check(['domain' => 'other.example.net', 'directory' => self::DIRECTORY])
            ->assertJson(['status' => 'Invalid', 'message' => 'The license isn\'t for this domain (other.example.net). Reissue it to move it.']);
        $this->check(['domain' => self::DOMAIN])->assertJson(['status' => 'Invalid', 'message' => 'The check didn\'t send the directory.']);
        $this->assertSame([self::DOMAIN], $license->fresh()->domains);
    }

    public function test_the_client_reissues_the_license_to_move_it(): void
    {
        $this->check(['domain' => self::DOMAIN, 'directory' => self::DIRECTORY]);
        $this->actingAs($this->service->client, 'client');

        $this->get("/portal/services/{$this->service->id}")->assertSee($this->service->license->key)->assertSee(self::DOMAIN)->assertSee('Reissue');
        $this->post("/portal/services/{$this->service->id}/license/reissue")->assertSessionHas('status', 'License reissued. It locks to the next place it is installed.');

        $this->assertSame([null, 1], [$this->service->license->fresh()->domains, $this->service->license->fresh()->reissues]);
        $this->check(['domain' => 'new.example.net', 'directory' => '/srv/shop'])->assertJson(['status' => 'Active', 'domains' => ['new.example.net']]);
    }

    public function test_only_staff_reissue_when_the_product_says_so(): void
    {
        $this->service->product->update(['module_config' => [...$this->service->product->module_config, 'reissue' => 'staff']]);
        $this->actingAs($this->service->client, 'client');

        $this->get("/portal/services/{$this->service->id}")->assertDontSee('Reissue</button>', false);
        $this->post("/portal/services/{$this->service->id}/license/reissue")->assertForbidden();

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/services/{$this->service->id}")->assertSee('License')->assertSee('Checks');
        $this->post("/admin/services/{$this->service->id}/license/reissue")->assertSessionHas('status');
        $this->assertSame(1, $this->service->license->fresh()->reissues);
    }

    public function test_the_license_follows_the_service(): void
    {
        $lifecycle = app(ServiceLifecycle::class);

        $lifecycle->suspend($this->service, 'overdue');
        $this->assertSame(License::SUSPENDED, $this->service->license->fresh()->status);
        $this->check(['domain' => self::DOMAIN, 'directory' => self::DIRECTORY])->assertJson(['status' => 'Suspended', 'message' => 'The license is suspended.']);

        $lifecycle->unsuspend($this->service->fresh());
        $this->check(['domain' => self::DOMAIN, 'directory' => self::DIRECTORY])->assertJson(['status' => 'Active']);

        $lifecycle->terminate($this->service->fresh());
        $this->check(['domain' => self::DOMAIN, 'directory' => self::DIRECTORY])->assertJson(['status' => 'Expired', 'message' => 'The license has expired.']);
    }

    public function test_unknown_keys_are_invalid_and_unsigned(): void
    {
        $answer = $this->postJson(self::CHECK, ['key' => 'NOPE', 'domain' => self::DOMAIN]);

        $answer->assertOk()->assertJson(['status' => 'Invalid', 'message' => 'Unknown license key.']);
        $this->assertNull($answer->headers->get(LicenseCheckController::SIGNATURE));
        $this->postJson(self::CHECK, [])->assertStatus(422);
    }

    public function test_locks_and_installs_follow_the_product(): void
    {
        $this->service->product->update(['module_config' => [...$this->service->product->module_config, 'lock' => 'domain', 'installs' => '2']]);

        $this->check(['domain' => 'one.example.com'])->assertJson(['status' => 'Active']);
        $this->check(['domain' => 'two.example.com'])->assertJson(['status' => 'Active', 'domains' => ['one.example.com', 'two.example.com'], 'ips' => []]);
        $this->check(['domain' => 'three.example.com'])->assertJson(['status' => 'Invalid']);
    }

    public function test_the_signing_secret_must_be_long_enough(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->put("/admin/products/{$this->service->product_id}", ['name' => 'Shop Pro', 'module' => 'licensing', 'module_config' => ['licensing' => ['secret' => 'short']]])
            ->assertSessionHasErrors('module_config.licensing.secret');
    }

    private function check(array $params): TestResponse
    {
        return $this->postJson(self::CHECK, ['key' => $this->service->license()->value('key'), ...$params]);
    }
}
