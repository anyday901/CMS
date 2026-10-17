<?php

namespace Tests\Feature;

use App\Import\Whmcs\EmailHistoryImporter;
use App\Import\Whmcs\ImportException;
use App\Import\Whmcs\WhmcsImporter;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\WhmcsDatabase;
use Tests\TestCase;

class WhmcsEmailImportTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionInterface $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 12:00:00');

        $this->whmcs = WhmcsDatabase::create();
        WhmcsDatabase::seed($this->whmcs);
    }

    public function test_the_emails_whmcs_sent_come_over_with_the_client(): void
    {
        WhmcsDatabase::seedEmails($this->whmcs);

        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $this->assertSame(['found' => 5, 'imported' => 4, 'skipped' => 1], $report->counts()[EmailHistoryImporter::SECTION]);
        $this->assertStringContainsString('client 99', implode(' ', $report->notes()[EmailHistoryImporter::SECTION]));

        $invoice = EmailLog::where('whmcs_id', 501)->sole();
        $this->assertSame([7, 'whmcs', WhmcsDatabase::ADA_EMAIL, ['bob@example.com'], 'Customer Invoice', EmailLog::SENT], [$invoice->client_id, $invoice->template, $invoice->to, $invoice->cc, $invoice->subject, $invoice->status]);
        $this->assertSame("Dear Ada,\n\nInvoice **#100** is due.\n\n[Pay now](https://billing.example.com/viewinvoice.php?id=100)", $invoice->body);
        $this->assertSame(['2025-03-01 09:00:00', '2025-03-01 09:00:00'], [$invoice->created_at->toDateTimeString(), $invoice->sent_at->toDateTimeString()]);

        // An old email with no To address went to the client's email.
        $welcome = EmailLog::where('whmcs_id', 500)->sole();
        $this->assertSame([WhmcsDatabase::ADA_EMAIL, 'Welcome & thanks', null], [$welcome->to, $welcome->subject, $welcome->cc]);

        $failed = EmailLog::where('whmcs_id', 502)->sole();
        $this->assertSame([EmailLog::FAILED, 'SMTP Error: Could not connect', null], [$failed->status, $failed->error, $failed->sent_at]);
        $this->assertSame(EmailLog::FAILED, EmailLog::where('whmcs_id', 503)->value('status'));
        $this->assertStringContainsString('never sent', (string) EmailLog::where('whmcs_id', 503)->value('error'));
    }

    public function test_staff_see_imported_emails_on_the_client_newest_first(): void
    {
        WhmcsDatabase::seedEmails($this->whmcs);
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        $this->actingAs(User::factory()->create(), 'web');

        $this->get('/admin/clients/7')->assertOk()
            ->assertSeeInOrder(['Recent emails', 'Queued', 'Overdue', 'Customer Invoice', 'Welcome &amp; thanks'], false);

        $this->get('/admin/emails/'.EmailLog::where('whmcs_id', 501)->value('id'))->assertOk()
            ->assertSee('Sent by WHMCS, before the switch')
            ->assertSee('<strong>#100</strong>', false)
            ->assertSee('href="https://billing.example.com/viewinvoice.php?id=100"', false)
            ->assertDontSee('alert(1)');
    }

    public function test_an_imported_install_gets_its_emails_by_running_again_without_doubling(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        $this->assertSame(0, EmailLog::count());

        // An email this app sent after the import stays at the top of the list.
        Bus::fake();
        $new = EmailLog::create(['client_id' => 7, 'template' => 'invoice_created', 'to' => WhmcsDatabase::ADA_EMAIL, 'subject' => 'Invoice #101', 'body' => 'Hi', 'status' => EmailLog::SENT, 'sent_at' => now()]);
        WhmcsDatabase::seedEmails($this->whmcs);

        $dryRun = EmailHistoryImporter::bringOver($this->whmcs, dryRun: true);
        $this->assertSame(4, $dryRun->counts()[EmailHistoryImporter::SECTION]['imported']);
        $this->assertSame(1, EmailLog::count());

        $report = EmailHistoryImporter::bringOver($this->whmcs, dryRun: false);
        $this->assertSame(['found' => 5, 'imported' => 4, 'skipped' => 1], $report->counts()[EmailHistoryImporter::SECTION]);
        $this->assertSame(5, EmailLog::count());
        $this->assertSame($new->id, Client::find(7)->emailLogs()->latest()->latest('id')->value('id'));

        $again = EmailHistoryImporter::bringOver($this->whmcs, dryRun: false);
        $this->assertSame(['found' => 5, 'imported' => 0, 'skipped' => 1], $again->counts()[EmailHistoryImporter::SECTION]);
        $this->assertStringContainsString('4 emails came over in an earlier import', implode(' ', $again->notes()[EmailHistoryImporter::SECTION]));
        $this->assertSame(5, EmailLog::count());
    }

    public function test_emails_go_only_to_the_same_client_as_in_whmcs(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        WhmcsDatabase::seedEmails($this->whmcs);

        // Since the import, both this app and WHMCS gave id 8 to a new client, and Ada changed her email here.
        $this->whmcs->table('tblclients')->insert(['id' => 8, 'firstname' => 'Wes', 'lastname' => 'Whmcs', 'email' => 'wes@example.com', 'status' => 'Active']);
        $this->whmcs->table('tblemails')->insert(['id' => 510, 'userid' => 8, 'subject' => 'For Wes', 'message' => 'Hi Wes', 'date' => '2026-10-16 09:00:00', 'to' => 'wes@example.com']);
        Client::factory()->create(['id' => 8, 'email' => 'cara@example.com']);
        Client::find(7)->forceFill(['email' => 'ada@new.example.com'])->save();

        $report = EmailHistoryImporter::bringOver($this->whmcs, dryRun: false);

        $this->assertSame(['found' => 6, 'imported' => 0, 'skipped' => 6], $report->counts()[EmailHistoryImporter::SECTION]);
        $notes = implode(' ', $report->notes()[EmailHistoryImporter::SECTION]);
        $this->assertStringContainsString("client 8, who isn't here", $notes);
        $this->assertStringContainsString("isn't the one here (ada@new.example.com)", $notes);
        $this->assertSame(0, EmailLog::count());
    }

    public function test_anonymised_clients_do_not_get_their_emails_back(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        Client::find(7)->forceFill(['anonymised_at' => now()])->save();
        WhmcsDatabase::seedEmails($this->whmcs);

        $report = EmailHistoryImporter::bringOver($this->whmcs, dryRun: false);

        $this->assertSame(0, $report->counts()[EmailHistoryImporter::SECTION]['imported']);
        $this->assertSame(0, EmailLog::count());
    }

    public function test_bringing_emails_over_needs_an_install_imported_from_whmcs(): void
    {
        $this->expectException(ImportException::class);

        EmailHistoryImporter::bringOver($this->whmcs, dryRun: false);
    }

    public function test_the_command_brings_emails_over_on_its_own(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        WhmcsDatabase::seedEmails($this->whmcs);
        $path = sys_get_temp_dir().'/whmcs-emails-'.uniqid().'.md';

        $this->artisan('whmcs:import', ['--emails' => true, '--report' => $path])
            ->expectsConfirmation('Bring the emails WHMCS sent to clients over now?', 'yes')
            ->expectsOutputToContain('Emails brought over.')
            ->assertSuccessful();

        $this->assertSame(4, EmailLog::count());
        unlink($path);
    }
}
