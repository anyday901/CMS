<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private const FIELDS = '/admin/custom-fields';

    private const VAT = 'GB123456789';

    private const VAT_NAME = 'VAT number';

    private const DEBIAN = 'Debian';

    private const RISK_NOTE = 'Chargeback in 2025';

    private const ROOT_SET = 'Root password set';

    private const HOST1 = 'web1.example.com';

    private const HOST2 = 'web2.example.com';

    public function test_staff_manage_custom_fields(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $product = Product::factory()->create();

        $this->post(self::FIELDS, ['entity' => 'client', 'name' => self::VAT_NAME, 'type' => 'text', 'required' => '1'])->assertRedirect(self::FIELDS);
        $this->post(self::FIELDS, ['entity' => 'service', 'product_id' => $product->id, 'name' => 'OS', 'type' => 'dropdown', 'options' => "Ubuntu\nDebian"])->assertRedirect();
        $this->post(self::FIELDS, ['entity' => 'service', 'name' => 'Size', 'type' => 'dropdown'])->assertSessionHasErrors('options');

        $this->get(self::FIELDS)->assertOk()->assertSee(self::VAT_NAME)->assertSee($product->name);
        $os = CustomField::where('name', 'OS')->sole();
        $this->assertSame(['Ubuntu', self::DEBIAN], $os->choices());

        $vat = CustomField::where('name', self::VAT_NAME)->sole();
        $this->put(self::FIELDS."/{$vat->id}", ['entity' => 'client', 'name' => 'VAT ID', 'type' => 'text', 'product_id' => $product->id])->assertRedirect();
        $this->assertSame(['VAT ID', false, null], [$vat->fresh()->name, $vat->fresh()->required, $vat->fresh()->product_id]);

        $this->delete(self::FIELDS."/{$vat->id}")->assertRedirect();
        $this->assertModelMissing($vat);

        $this->actingAs(User::factory()->create(['role' => StaffRole::Billing]), 'web');
        $this->get(self::FIELDS)->assertForbidden();
    }

    public function test_client_fields_on_the_admin_and_portal_pages(): void
    {
        $vat = CustomField::create(['entity' => 'client', 'name' => self::VAT_NAME, 'type' => 'text', 'required' => true]);
        $note = CustomField::create(['entity' => 'client', 'name' => 'Risk notes', 'type' => 'textarea', 'admin_only' => true]);
        $client = Client::factory()->create();
        $details = ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => $client->email, 'status' => 'active'];

        $this->actingAs(User::factory()->create(), 'web');
        $this->put("/admin/clients/{$client->id}", $details + ['custom_fields' => [$vat->id => '']])->assertSessionHasErrors("custom_fields.{$vat->id}");
        $this->put("/admin/clients/{$client->id}", $details + ['custom_fields' => [$vat->id => self::VAT, $note->id => self::RISK_NOTE]])->assertRedirect();
        $this->assertSame([(string) $vat->id => self::VAT, (string) $note->id => self::RISK_NOTE], $client->fresh()->custom_fields);
        $this->get("/admin/clients/{$client->id}")->assertSee(self::VAT)->assertSee(self::RISK_NOTE);

        // Clients see and change only the fields that aren't staff only.
        $this->actingAs($client->fresh(), 'client');
        $this->get('/portal/account')->assertSee(self::VAT_NAME)->assertDontSee('Risk notes');
        $this->put('/portal/account', $details + ['custom_fields' => [$vat->id => 'GB999', $note->id => 'All good']])->assertRedirect();
        $this->assertSame([(string) $vat->id => 'GB999', (string) $note->id => self::RISK_NOTE], $client->fresh()->custom_fields);
    }

    public function test_service_fields_follow_the_product_and_are_hidden_from_clients_when_staff_only(): void
    {
        Mail::fake();
        $product = Product::factory()->create();
        $product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 1000]);
        $other = Product::factory()->create();
        $hostname = CustomField::create(['entity' => 'service', 'name' => 'Hostname', 'type' => 'text', 'required' => true]);
        $os = CustomField::create(['entity' => 'service', 'product_id' => $product->id, 'name' => 'OS', 'type' => 'dropdown', 'options' => "Ubuntu\nDebian"]);
        $other = CustomField::create(['entity' => 'service', 'product_id' => $other->id, 'name' => 'Region', 'type' => 'text', 'required' => true]);
        $root = CustomField::create(['entity' => 'service', 'name' => self::ROOT_SET, 'type' => 'checkbox', 'admin_only' => true]);
        $client = Client::factory()->create();
        $order = ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'start_date' => '2026-10-11', 'invoice' => '0'];

        $this->actingAs(User::factory()->create(), 'web');
        $this->post("/admin/clients/{$client->id}/services", $order + ['custom_fields' => [$os->id => 'Windows']])
            ->assertSessionHasErrors(["custom_fields.{$hostname->id}", "custom_fields.{$os->id}"]);
        $this->post("/admin/clients/{$client->id}/services", $order + ['custom_fields' => [$hostname->id => self::HOST1, $os->id => self::DEBIAN, $root->id => '1']])->assertRedirect();

        $service = Service::sole();
        $this->assertSame([(string) $hostname->id => self::HOST1, (string) $os->id => self::DEBIAN, (string) $root->id => '1'], $service->custom_fields);
        $this->assertSame(['Hostname' => self::HOST1, 'OS' => self::DEBIAN, self::ROOT_SET => '1'], $service->customFieldsByName());
        $this->assertArrayNotHasKey('Region', $service->customFieldsByName());

        $this->put("/admin/services/{$service->id}", ['custom_fields' => [$hostname->id => self::HOST2, $os->id => '', $root->id => '0']])->assertRedirect();
        $this->assertSame([(string) $hostname->id => self::HOST2], $service->fresh()->custom_fields);

        $this->actingAs($client, 'client');
        $this->get("/portal/services/{$service->id}")->assertSee(self::HOST2)->assertDontSee(self::ROOT_SET);
    }
}
