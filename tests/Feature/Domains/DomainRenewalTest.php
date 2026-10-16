<?php

namespace Tests\Feature\Domains;

use App\Billing\PaymentRecorder;
use App\Domains\DomainInfo;
use App\Domains\DomainRenewals;
use App\Domains\DomainSync;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tld;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\DomainSetup;
use Tests\TestCase;

class DomainRenewalTest extends TestCase
{
    use DomainSetup, RefreshDatabase;

    private const EXPIRY = '2026-11-10';

    private const REMINDER = 'domain_renewal_reminder';

    private Client $client;

    private Tld $com;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-16'));
        $this->fakeRegistrar();
        $this->com = $this->tld();
        $this->client = $this->registrant();
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->for($this->client)->create([
            'tld_id' => $this->com->id, 'registrar' => 'fake', 'name' => 'example.com',
            'expiry_date' => self::EXPIRY, 'next_due_date' => self::EXPIRY, 'recurring_amount' => 1500, ...$attributes,
        ]);
    }

    public function test_auto_renewing_domains_are_invoiced_before_expiry_once(): void
    {
        $domain = $this->domain();
        $second = $this->domain(['name' => 'second.com', 'expiry_date' => '2026-11-01', 'next_due_date' => '2026-11-01', 'recurring_amount' => 1800]);
        $this->domain(['name' => 'manual.com', 'auto_renew' => false]);
        $this->domain(['name' => 'later.com', 'expiry_date' => '2027-01-01', 'next_due_date' => '2027-01-01']);

        $this->artisan('domains:run')->assertSuccessful();

        $invoice = Invoice::sole();
        $this->assertSame(1500 + 1800, $invoice->total);
        $this->assertSame('2026-11-01', $invoice->due_date->toDateString());
        $this->assertSame([$domain->id, $second->id], $invoice->items->pluck('domain_id')->sort()->values()->all());
        $this->assertSame('Domain renewal: example.com, 1 year (11/10/2026 - 11/09/2027)', $invoice->items->firstWhere('domain_id', $domain->id)->description);
        $this->assertTrue(EmailLog::where('template', 'invoice_created')->exists());

        $this->artisan('domains:run')->assertSuccessful();
        $this->assertSame(1, Invoice::count());
    }

    public function test_paying_a_renewal_renews_at_the_registrar(): void
    {
        $domain = $this->domain();
        $invoice = app(DomainRenewals::class)->generate(today())->sole();

        app(PaymentRecorder::class)->record($invoice, $invoice->total, 'manual');

        $domain->refresh();
        $this->assertSame([['renew', 'example.com', 1]], $this->registrar->calls);
        $this->assertSame('2027-11-10', $domain->expiry_date->toDateString());
        $this->assertSame('2027-11-10', $domain->next_due_date->toDateString());
        $this->assertTrue(EmailLog::where('template', 'domain_renewed')->exists());
    }

    public function test_clients_renew_from_the_portal_for_another_term(): void
    {
        $domain = $this->domain(['auto_renew' => false, 'id_protection' => true, 'recurring_amount' => 1800]);
        $this->actingAs($this->client, 'client');

        $this->post(route('portal.domains.renew', $domain), ['years' => 2])->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame(3000 + 600, $invoice->total);
        $this->assertSame('2028-11-09', $invoice->items()->sole()->period_end->toDateString());

        // Asking again shows the same invoice.
        $this->post(route('portal.domains.renew', $domain), ['years' => 1])->assertRedirect(route('portal.invoices.show', $invoice));
        $this->assertSame(1, Invoice::count());

        app(PaymentRecorder::class)->record($invoice, $invoice->total, 'manual');
        $this->assertSame([['renew', 'example.com', 2]], $this->registrar->calls);
        $this->assertSame('2028-11-10', $domain->refresh()->expiry_date->toDateString());
    }

    public function test_pending_domains_cant_be_renewed(): void
    {
        $domain = $this->domain(['status' => DomainStatus::Pending, 'next_due_date' => null]);
        $this->actingAs($this->client, 'client');

        $this->post(route('portal.domains.renew', $domain), ['years' => 1])->assertSessionHasErrors('renew');
    }

    public function test_reminders_go_out_at_each_threshold_once(): void
    {
        config(['domains.reminder_days' => [30, 7, 1]]);
        $this->domain();
        $this->domain(['name' => 'off.com', 'auto_renew' => false]);

        $this->artisan('domains:run');
        $this->artisan('domains:run');

        $reminders = EmailLog::where('template', self::REMINDER)->get();
        $this->assertCount(2, $reminders);
        $this->assertStringContainsString("Auto-renew is off, so it won't renew unless you renew it in the portal.", $reminders->firstWhere(fn ($log) => str_contains($log->body, 'off.com'))->body);
        $this->assertStringContainsString('It renews automatically once its renewal invoice is paid.', $reminders->firstWhere(fn ($log) => str_contains($log->body, 'example.com'))->body);

        $this->travelTo(CarbonImmutable::parse('2026-11-04'));
        $this->artisan('domains:run');
        // example.com's renewal invoice is still unpaid, so it gets the 7 day reminder too.
        $this->assertSame(4, EmailLog::where('template', self::REMINDER)->count());
    }

    public function test_domains_whose_renewal_is_paid_get_no_reminder_or_expiry_notice(): void
    {
        $domain = $this->domain();
        $this->registrar->refuse = 'Registrar is down';
        $invoice = app(DomainRenewals::class)->generate(today())->sole();
        app(PaymentRecorder::class)->record($invoice, $invoice->total, 'manual');
        $this->assertSame('failed', $domain->refresh()->registrar_status);

        $this->travelTo(CarbonImmutable::parse('2026-11-20'));
        $this->artisan('domains:run');

        $this->assertSame(DomainStatus::Active, $domain->refresh()->status);
        $this->assertSame(0, EmailLog::whereIn('template', [self::REMINDER, 'domain_expired'])->count());
    }

    public function test_unrenewed_domains_expire_with_one_notice(): void
    {
        $domain = $this->domain(['auto_renew' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11'));

        $this->artisan('domains:run');
        $this->assertSame(DomainStatus::Expired, $domain->refresh()->status);
        $domain->update(['status' => DomainStatus::Active]);
        $this->artisan('domains:run');

        $this->assertSame(1, EmailLog::where('template', 'domain_expired')->count());
    }

    public function test_the_sync_takes_expiry_dates_renewed_elsewhere(): void
    {
        $domain = $this->domain();
        $this->registrar->info = new DomainInfo(DomainInfo::ACTIVE, CarbonImmutable::parse('2027-11-10'), [], false, true);

        $this->assertSame(['synced' => 1, 'failed' => 0], app(DomainSync::class)->run());

        $domain->refresh();
        $this->assertSame('2027-11-10', $domain->expiry_date->toDateString());
        $this->assertSame('2027-11-10', $domain->next_due_date->toDateString());
        $this->assertFalse($domain->locked);
        $this->assertTrue($domain->id_protection);
        $this->assertNotNull($domain->synced_at);
    }

    public function test_the_sync_keeps_the_due_date_an_open_renewal_invoice_is_for(): void
    {
        $domain = $this->domain();
        app(DomainRenewals::class)->generate(today());
        $this->registrar->info = new DomainInfo(DomainInfo::ACTIVE, CarbonImmutable::parse('2026-11-12'));

        $this->artisan('domains:sync');

        $this->assertSame('2026-11-12', $domain->refresh()->expiry_date->toDateString());
        $this->assertSame(self::EXPIRY, $domain->next_due_date->toDateString());
    }

    public function test_the_sync_notices_expired_and_departed_domains_and_records_errors(): void
    {
        $expired = $this->domain();
        $gone = $this->domain(['name' => 'gone.com']);
        $broken = $this->domain(['name' => 'broken.com']);
        $this->registrar->infoFor = [
            'example.com' => new DomainInfo(DomainInfo::EXPIRED, CarbonImmutable::parse('2026-10-01')),
            'gone.com' => new DomainInfo(DomainInfo::GONE),
            'broken.com' => 'Domain not found in account',
        ];

        $this->assertSame(['synced' => 2, 'failed' => 1], app(DomainSync::class)->run());

        $this->assertSame(DomainStatus::Expired, $expired->refresh()->status);
        $this->assertSame(DomainStatus::TransferredAway, $gone->refresh()->status);
        $this->assertSame('Domain not found in account', $broken->refresh()->sync_error);
    }

    public function test_cancelled_renewal_invoices_are_invoiced_again(): void
    {
        $this->domain();
        $invoice = app(DomainRenewals::class)->generate(today())->sole();
        $invoice->update(['status' => InvoiceStatus::Cancelled]);

        $this->assertCount(1, app(DomainRenewals::class)->generate(today()));
        $this->assertSame(2, InvoiceItem::where('type', InvoiceItem::TYPE_DOMAIN_RENEW)->count());
    }
}
