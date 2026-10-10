<?php

namespace Tests\Feature;

use App\Billing\ServiceLifecycle;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WebhookModuleTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://hooks.example.test/provision';

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $product = Product::factory()->create(['name' => 'VPS', 'module' => 'webhook', 'module_config' => ['url' => self::URL, 'secret' => 's3cret']]);
        $client = Client::factory()->create(['email' => 'ada@example.test']);
        $this->service = Service::factory()->for($product)->for($client)->pending()->create();
    }

    public function test_actions_are_posted_as_signed_json_and_reply_data_is_kept(): void
    {
        Http::fake([self::URL => Http::response(['message' => 'Created vm-42', 'data' => ['vm' => 'vm-42']])]);

        app(ServiceLifecycle::class)->activate($this->service);

        Http::assertSent(function (Request $request) {
            $body = $request->body();

            return $request->url() === self::URL
                && $request['action'] === 'create'
                && $request['service']['product']['name'] === 'VPS'
                && $request['client']['email'] === 'ada@example.test'
                && $request->header('X-Webhook-Delivery')[0] === "service-{$this->service->id}-action-1"
                && $request->header('X-Webhook-Signature')[0] === 'sha256='.hash_hmac('sha256', $body, 's3cret');
        });
        $this->service->refresh();
        $this->assertSame('done', $this->service->provisioning_status);
        $this->assertSame(['vm' => 'vm-42'], $this->service->provisioning_data);

        app(ServiceLifecycle::class)->suspend($this->service, 'overdue');
        Http::assertSent(fn (Request $request) => $request['action'] === 'suspend'
            && $request['service']['provisioning_data'] === ['vm' => 'vm-42']
            && $request->header('X-Webhook-Delivery')[0] === "service-{$this->service->id}-action-2");
    }

    public function test_an_error_reply_fails_the_action_with_its_message(): void
    {
        Http::fake([self::URL => Http::response(['message' => 'No capacity in region'], 503)]);

        app(ServiceLifecycle::class)->activate($this->service);

        $this->service->refresh();
        $this->assertSame('failed', $this->service->provisioning_status);
        $this->assertSame('The webhook answered HTTP 503: No capacity in region', $this->service->provisioning_error);
    }

    public function test_an_unreachable_url_fails_the_action(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        app(ServiceLifecycle::class)->activate($this->service);

        $this->assertSame('Could not resolve host', $this->service->fresh()->provisioning_error);
    }

    public function test_requests_are_unsigned_without_a_secret(): void
    {
        $this->service->product->update(['module_config' => ['url' => self::URL]]);
        Http::fake([self::URL => Http::response()]);

        app(ServiceLifecycle::class)->activate($this->service);

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Webhook-Signature'));
        $this->assertSame('done', $this->service->fresh()->provisioning_status);
    }

    public function test_the_product_needs_a_web_address(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $fields = ['name' => 'VPS2', 'active' => '1', 'taxable' => '1', 'module' => 'webhook', 'prices' => ['monthly' => ['price' => '10']]];

        $this->post('/admin/products', $fields + ['module_config' => ['webhook' => ['url' => 'ftp://example.test']]])
            ->assertSessionHasErrors('module_config.webhook.url');
        $this->post('/admin/products', $fields + ['module_config' => ['webhook' => ['url' => self::URL]]])->assertRedirect();
    }
}
