<?php

namespace Tests\Feature;

use App\Billing\PaymentRecorder;
use App\Billing\ServiceLifecycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeProvisioningModule;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['provisioning.modules' => [FakeProvisioningModule::class]]);
        FakeProvisioningModule::$calls = [];
        FakeProvisioningModule::$failWith = null;
        FakeProvisioningModule::$throw = false;

        $this->product = Product::factory()->create(['module' => 'fake', 'module_config' => ['server' => 'node1']]);
    }

    public function test_paying_the_first_invoice_creates_the_service_on_the_module(): void
    {
        $service = Service::factory()->for($this->product)->create(['status' => ServiceStatus::Pending]);
        $invoice = Invoice::create(['client_id' => $service->client_id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['service_id' => $service->id, 'type' => InvoiceItem::TYPE_SERVICE, 'description' => 'VPS', 'amount' => 1000, 'period_start' => $service->next_due_date]);
        $invoice->recalculate();

        app(PaymentRecorder::class)->record($invoice, 1000, 'cash');

        $this->assertSame([['create', $service->id]], FakeProvisioningModule::$calls);
        $service->refresh();
        $this->assertSame('done', $service->provisioning_status);
        $this->assertSame(['remote_id' => "vm-{$service->id}"], $service->provisioning_data);
        $this->assertNotNull($service->provisioned_at);
    }

    public function test_status_changes_run_the_matching_actions(): void
    {
        $service = Service::factory()->for($this->product)->create();
        $lifecycle = app(ServiceLifecycle::class);

        $lifecycle->suspend($service, 'overdue');
        $lifecycle->unsuspend($service);
        $lifecycle->terminate($service);

        $this->assertSame(['suspend', 'unsuspend', 'terminate'], array_column(FakeProvisioningModule::$calls, 0));
    }

    public function test_products_without_a_module_are_left_alone(): void
    {
        $service = Service::factory()->create();
        app(ServiceLifecycle::class)->suspend($service, 'overdue');

        $this->assertSame([], FakeProvisioningModule::$calls);
        $this->assertNull($service->fresh()->provisioning_status);
    }

    public function test_failures_are_shown_to_staff_who_can_run_the_action_again(): void
    {
        $service = Service::factory()->for($this->product)->create();
        FakeProvisioningModule::$throw = true;

        app(ServiceLifecycle::class)->suspend($service, 'overdue');

        $this->assertSame('failed', $service->fresh()->provisioning_status);
        $this->assertSame('Connection refused', $service->fresh()->provisioning_error);

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/services/{$service->id}")->assertSee('Provisioning: Fake Panel')->assertSee('Connection refused');

        FakeProvisioningModule::$throw = false;
        $this->post("/admin/services/{$service->id}/provision", ['action' => 'suspend'])->assertRedirect();

        $this->assertSame('done', $service->fresh()->provisioning_status);
        $this->assertNull($service->fresh()->provisioning_error);
        $this->assertCount(2, FakeProvisioningModule::$calls);
    }

    public function test_a_module_can_report_a_failure_without_throwing(): void
    {
        $service = Service::factory()->for($this->product)->create();
        FakeProvisioningModule::$failWith = 'Plan not found';

        app(ServiceLifecycle::class)->terminate($service);

        $this->assertSame('Plan not found', $service->fresh()->provisioning_error);
    }

    public function test_services_added_without_an_invoice_are_created_right_away(): void
    {
        $client = Client::factory()->create();
        $this->product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 1000]);
        $this->actingAs(User::factory()->create(), 'web');

        $this->post("/admin/clients/{$client->id}/services", [
            'product_id' => $this->product->id, 'billing_cycle' => 'monthly', 'start_date' => today()->toDateString(), 'invoice' => '0',
        ])->assertRedirect();

        $this->assertSame('create', FakeProvisioningModule::$calls[0][0] ?? null);
    }

    public function test_products_choose_a_module_and_its_settings(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->get('/admin/products/create')->assertSee('Fake Panel')->assertSee('Package name on the server');

        $fields = ['name' => 'VPS', 'active' => '1', 'taxable' => '1', 'module' => 'fake', 'prices' => ['monthly' => ['price' => '10']]];
        $this->post('/admin/products', $fields)->assertSessionHasErrors('module_config.fake.server');
        $this->post('/admin/products', $fields + ['module_config' => ['fake' => ['server' => 'node2', 'plan' => '']]])->assertRedirect();

        $product = Product::where('name', 'VPS')->sole();
        $this->assertSame('fake', $product->module);
        $this->assertSame(['server' => 'node2'], $product->module_config);

        $this->post('/admin/products', ['name' => 'Bad', 'module' => 'nope'] + $fields)->assertSessionHasErrors('module');
    }
}
