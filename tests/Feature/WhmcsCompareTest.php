<?php

namespace Tests\Feature;

use App\Billing\InvoiceGenerator;
use App\Import\Whmcs\BillingComparison;
use App\Import\Whmcs\WhmcsImporter;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\WhmcsDatabase;
use Tests\TestCase;

class WhmcsCompareTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-12-01';

    private const TO = '2026-12-31';

    private ConnectionInterface $whmcs;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->whmcs = WhmcsDatabase::create();
        WhmcsDatabase::seed($this->whmcs);
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        // November's renewal was paid, so both systems bill December next.
        Service::findOrFail(40)->forceFill(['next_due_date' => '2026-12-15'])->save();
        Invoice::findOrFail(101)->forceFill(['status' => 'paid'])->save();
        $this->whmcsRenewal(102, 40, '2026-12-15', '13.00');
    }

    public function test_a_simulated_run_matches_whmcs_and_saves_nothing(): void
    {
        $lines = $this->compare(simulate: true);

        $this->assertSame([BillingComparison::MATCHED], array_column($lines, 'result'));
        $this->assertSame(2, Invoice::count());
        $this->assertSame(0, EmailLog::count());
    }

    public function test_it_lists_lines_that_differ(): void
    {
        Service::findOrFail(40)->forceFill(['recurring_amount' => 1400])->save();
        $this->whmcsRenewal(103, 77, '2026-12-20', '9.00');

        $lines = collect($this->compare(simulate: true))->keyBy('service_id');

        $this->assertSame([BillingComparison::DIFFERENT, 1300, 1400], [$lines[40]['result'], $lines[40]['whmcs'], $lines[40]['here']]);
        $this->assertSame(BillingComparison::ONLY_WHMCS, $lines[77]['result']);
        $this->assertStringContainsString('Service 77 (not imported)', BillingComparison::toMarkdown($lines->values()->all(), Carbon::parse(self::FROM), Carbon::parse(self::TO), true));
    }

    public function test_it_compares_invoices_this_app_really_made(): void
    {
        $this->assertSame(BillingComparison::ONLY_WHMCS, $this->compare(simulate: false)[0]['result']);

        app(InvoiceGenerator::class)->generate(Carbon::parse('2026-12-08'));

        $this->assertSame(BillingComparison::MATCHED, $this->compare(simulate: false)[0]['result']);
    }

    public function test_the_command_reports_the_comparison(): void
    {
        $path = sys_get_temp_dir().'/whmcs-compare-'.uniqid().'.md';

        $this->artisan('whmcs:compare', ['--from' => self::FROM, '--to' => self::TO, '--simulate' => true, '--report' => $path])
            ->expectsOutputToContain('Both systems billed the same.')
            ->assertSuccessful();

        $this->assertStringContainsString('Matched: 1', (string) file_get_contents($path));
        unlink($path);
    }

    /** @return list<array<string, mixed>> */
    private function compare(bool $simulate): array
    {
        return app(BillingComparison::class)->compare($this->whmcs, Carbon::parse(self::FROM), Carbon::parse(self::TO), $simulate);
    }

    private function whmcsRenewal(int $id, int $serviceId, string $due, string $amount): void
    {
        $this->whmcs->table('tblinvoices')->insert(['id' => $id, 'userid' => 7, 'date' => $due, 'duedate' => $due, 'subtotal' => $amount, 'total' => $amount, 'status' => 'Unpaid']);
        $this->whmcs->table('tblinvoiceitems')->insert(['invoiceid' => $id, 'userid' => 7, 'type' => 'Hosting', 'relid' => $serviceId, 'description' => 'Renewal', 'amount' => $amount, 'duedate' => $due]);
    }
}
