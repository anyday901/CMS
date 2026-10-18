<?php

namespace Tests\Feature\Platform;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Promotion;
use App\Support\Language;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainSetup;
use Tests\TestCase;

/** Portal and store pages, and the messages they show, in a visitor's language. */
class PortalTranslationTest extends TestCase
{
    use DomainSetup, RefreshDatabase;

    private const GERMAN = 'de';

    private Client $client;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::save(['help.languages' => [self::GERMAN, 'fr', 'es']]);
        $this->fakeRegistrar();
        $tld = $this->tld();
        $this->client = $this->registrant(['language' => self::GERMAN]);
        $this->domain = Domain::factory()->for($this->client)->create([
            'tld_id' => $tld->id, 'registrar' => 'fake', 'name' => 'example.com', 'nameservers' => ['ns1.host.test', 'ns2.host.test'],
        ]);
    }

    public function test_the_domain_pages_are_shown_in_the_clients_language(): void
    {
        $this->actingAs($this->client, 'client');

        $this->get(route('portal.domains.index'))->assertOk()
            ->assertSee('Domain registrieren oder übertragen')
            ->assertSee('Automatische Verlängerung');
        $this->get(route('portal.domains.show', $this->domain))->assertOk()
            ->assertSee('Nameserver speichern')
            ->assertSee('Transfersperre')
            ->assertDontSee('Save nameservers');
        $this->get(route('domains.index', ['domain' => 'example.xyz']))->assertOk()
            ->assertSee('Domain finden')
            ->assertSee('Diese Endung bieten wir nicht an. Versuchen Sie eine dieser: .com.');
    }

    public function test_validation_cart_and_registrar_messages_are_translated(): void
    {
        $this->withSession([Language::SESSION_KEY => self::GERMAN]);

        $this->post('/portal/login', ['email' => '', 'password' => ''])
            ->assertSessionHasErrors(['email' => 'E-Mail-Adresse ist erforderlich.']);
        $this->post(route('cart.promotion'), ['code' => 'NOPE'])
            ->assertSessionHasErrors(['code' => 'Der Code NOPE ist ungültig.']);

        $this->actingAs($this->client, 'client')
            ->put(route('portal.domains.nameservers', $this->domain), ['nameservers' => ['ns1.example.net']])
            ->assertSessionHasErrors(['nameservers' => 'Geben Sie mindestens zwei Nameserver ein.']);
    }

    public function test_invoice_pdfs_are_written_in_the_current_language(): void
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'status' => InvoiceStatus::Unpaid, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 1000]);
        $data = ['invoice' => $invoice->recalculate()->load('items', 'transactions', 'client'), 'company' => config('billing.company')];

        $english = view('pdf.invoice', $data)->render();
        $this->assertStringContainsString('Bill to', $english);
        $this->assertStringContainsString('Balance due', $english);

        app()->setLocale(self::GERMAN);
        $german = view('pdf.invoice', $data)->render();
        app()->setLocale('en');
        $this->assertStringContainsString('Rechnung an', $german);
        $this->assertStringContainsString('<html lang="de"', $german);
        $this->assertStringNotContainsString('Bill to', $german);
    }

    public function test_promotion_summaries_follow_the_locale_and_english_stays_the_default(): void
    {
        $promotion = new Promotion(['code' => 'TENTH', 'type' => Promotion::PERCENT, 'value' => 1000, 'recurring' => false]);
        $this->assertSame('10% off the first payment', $promotion->summary());

        app()->setLocale(self::GERMAN);
        $this->assertSame('10% Rabatt auf die erste Zahlung', $promotion->summary());
        app()->setLocale('en');

        $this->actingAs($this->client->forceFill(['language' => null]), 'client');
        $this->get(route('portal.domains.show', $this->domain))->assertOk()->assertSee('Save nameservers');
    }
}
