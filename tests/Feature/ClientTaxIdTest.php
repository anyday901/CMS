<?php

namespace Tests\Feature;

use App\Clients\ClientMerger;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Privacy\ClientDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\MakesApiKeys;
use Tests\TestCase;

class ClientTaxIdTest extends TestCase
{
    use MakesApiKeys, RefreshDatabase;

    private const TAX_ID_LABEL = 'Tax ID: ';

    private const TAX_ID = '12-3456789';

    public function test_clients_and_staff_set_a_tax_id(): void
    {
        Http::fake();
        $client = Client::factory()->create();

        $this->actingAs($client, 'client')->get('/portal/account')->assertOk()->assertSee('Tax ID / VAT number (optional)');
        $this->put('/portal/account', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => $client->email, 'tax_id' => ' '.self::TAX_ID.' '])->assertSessionHasNoErrors();
        $this->assertSame(self::TAX_ID, $client->refresh()->tax_id);

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/clients/{$client->id}/edit")->assertOk()->assertSee(self::TAX_ID);
        $this->put("/admin/clients/{$client->id}", ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => $client->email, 'status' => 'active', 'tax_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($client->refresh()->tax_id);

        // Checking EU VAT numbers is off by default.
        Http::assertNothingSent();
    }

    public function test_the_tax_id_is_printed_on_invoices_and_quotes(): void
    {
        $client = Client::factory()->create(['tax_id' => self::TAX_ID]);
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $quote = Quote::create(['client_id' => $client->id, 'subject' => 'Work', 'currency' => 'USD', 'issue_date' => today(), 'valid_until' => today()->addDays(30)]);

        // Each keeps the number it was issued with.
        $client->update(['tax_id' => 'changed']);
        $this->assertSame(self::TAX_ID, $invoice->client_tax_id);

        $company = config('billing.company');
        $this->assertStringContainsString(self::TAX_ID_LABEL.self::TAX_ID, view('pdf.invoice', ['invoice' => $invoice, 'company' => $company])->render());
        $this->assertStringContainsString(self::TAX_ID_LABEL.self::TAX_ID, view('pdf.quote', ['quote' => $quote, 'company' => $company])->render());
        $this->actingAs($client, 'client')->get("/portal/invoices/{$invoice->id}")->assertOk()->assertSee(self::TAX_ID);
        $this->actingAs(User::factory()->create(), 'web')->get("/admin/quotes/{$quote->id}")->assertOk()->assertSee(self::TAX_ID);
    }

    public function test_the_tax_id_is_in_the_data_export_merge_and_api(): void
    {
        $client = Client::factory()->create(['tax_id' => self::TAX_ID]);
        $this->assertSame(self::TAX_ID, app(ClientDataExport::class)->build($client)['client']['tax_id']);

        $this->actingAs(User::factory()->create(), 'web');
        $into = Client::factory()->create();
        app(ClientMerger::class)->merge($client, $into);
        $this->assertStringContainsString(self::TAX_ID_LABEL.self::TAX_ID, $into->clientNotes()->sole()->body);

        $this->withApiKey();
        $this->patchJson("/api/v1/clients/{$into->id}", ['tax_id' => 'GB 123 4567 89'])->assertOk()->assertJsonPath('data.tax_id', 'GB 123 4567 89');
        $this->getJson("/api/v1/clients/{$into->id}")->assertJsonPath('data.tax_id_verified_at', null);
    }
}
