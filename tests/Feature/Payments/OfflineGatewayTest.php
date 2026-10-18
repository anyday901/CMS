<?php

namespace Tests\Feature\Payments;

use App\Billing\InvoicePdf;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Payments\GatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const MAIL_IN_LABEL = 'Mail-in payment';

    private const ACCOUNT_LINE = 'Account 12345678';

    private const SETTINGS_URL = '/admin/settings';

    private const BANK_DETAILS = "Example Bank\nAccount 12345678";

    private const POSTAL_ADDRESS = 'Post cheques to 1 Main Street, Springfield';

    private Client $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create();
        $this->invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'amount' => 1000]);
        $this->invoice->recalculate();
    }

    public function test_they_are_off_until_they_have_instructions(): void
    {
        $keys = app(GatewayRegistry::class)->enabled()->map->key()->all();

        $this->assertNotContains('bank_transfer', $keys);
        $this->assertNotContains('mail_in', $keys);
        $this->actingAs($this->client, 'client')->get(route('portal.invoices.show', $this->invoice))
            ->assertOk()->assertDontSee(self::MAIL_IN_LABEL);
    }

    public function test_clients_see_the_instructions_with_the_invoice_number_to_quote(): void
    {
        config(['payments.bank_transfer.instructions' => self::BANK_DETAILS, 'payments.mail_in.instructions' => self::POSTAL_ADDRESS]);

        $this->actingAs($this->client, 'client')->get(route('portal.invoices.show', $this->invoice))->assertOk()
            ->assertSee('Bank transfer')->assertSee('Example Bank<br />', false)->assertSee(self::ACCOUNT_LINE)
            ->assertSee(self::MAIL_IN_LABEL)->assertSee(self::POSTAL_ADDRESS)
            ->assertSee("Include invoice #{$this->invoice->number} as the reference");
    }

    public function test_the_instructions_are_escaped(): void
    {
        config(['payments.bank_transfer.instructions' => '<script>alert(1)</script>']);

        $this->actingAs($this->client, 'client')->get(route('portal.invoices.show', $this->invoice))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_unpaid_invoice_pdfs_carry_the_instructions(): void
    {
        config(['payments.bank_transfer.instructions' => self::BANK_DETAILS]);
        $pdf = app(InvoicePdf::class);

        $html = view('pdf.invoice', [
            'invoice' => $this->invoice->load(['client', 'items', 'transactions']),
            'company' => config('billing.company'),
            'offlineGateways' => app(GatewayRegistry::class)->enabled(),
        ])->render();
        $this->assertStringContainsString(self::ACCOUNT_LINE, $html);
        $this->assertStringStartsWith('%PDF', $pdf->output($this->invoice));
    }

    public function test_staff_set_the_instructions_and_record_a_mail_in_payment(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->put(self::SETTINGS_URL, ['bank_transfer_instructions' => self::BANK_DETAILS, 'mail_in_instructions' => '  '])->assertRedirect(self::SETTINGS_URL);
        $this->assertSame(self::BANK_DETAILS, config('payments.bank_transfer.instructions'));
        $this->assertNull(config('payments.mail_in.instructions'));
        $this->get(self::SETTINGS_URL)->assertOk()->assertSee(self::ACCOUNT_LINE);

        $this->post(route('admin.invoices.pay', $this->invoice), ['amount' => '10.00', 'method' => 'mail_in', 'reference' => 'Cheque 1001'])->assertRedirect();
        $this->assertSame(self::MAIL_IN_LABEL, $this->invoice->transactions()->sole()->methodLabel());
        $this->assertSame('paid', $this->invoice->fresh()->status->value);
    }
}
