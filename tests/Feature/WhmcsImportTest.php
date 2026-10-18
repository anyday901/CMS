<?php

namespace Tests\Feature;

use App\Auth\LegacyPassword;
use App\Billing\InvoiceGenerator;
use App\Enums\InvoiceStatus;
use App\Import\Whmcs\ImportException;
use App\Import\Whmcs\WhmcsImporter;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CustomField;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\Todo;
use App\Tickets\InboundMail;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WhmcsDatabase;
use Tests\TestCase;

class WhmcsImportTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'ada@example.com';

    private const JANE = 'jane@example.com';

    private const NOTE_TIME = '2024-03-01 10:00:00';

    private const LOGIN = '/portal/login';

    private ConnectionInterface $whmcs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->whmcs = WhmcsDatabase::create();
        WhmcsDatabase::seed($this->whmcs);
    }

    public function test_a_dry_run_reports_everything_and_saves_nothing(): void
    {
        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: true);

        $this->assertSame(['found' => 1, 'imported' => 1, 'skipped' => 0], $report->counts()['Clients']);
        $this->assertSame(['found' => 1, 'imported' => 1, 'skipped' => 0], $report->counts()['Services']);
        $this->assertSame(['found' => 2, 'imported' => 2, 'skipped' => 0], $report->counts()['Invoices']);
        $this->assertSame(['found' => 1, 'imported' => 0, 'skipped' => 1], $report->counts()['Ticket attachments']);
        $this->assertSame([], $report->mismatches());
        $this->assertStringContainsString('cpanel module', $report->toMarkdown());

        $this->assertSame(0, Client::count());
        $this->assertSame(0, Invoice::count());
        $this->assertNull(Setting::find(WhmcsImporter::SETTING));
    }

    public function test_it_imports_clients_billing_and_tickets(): void
    {
        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        $this->assertSame([], $report->mismatches());

        $client = Client::findOrFail(7);
        $this->assertSame(["O'Neil", 'Ada & Co', self::EMAIL, 'USD', 1250], [$client->last_name, $client->company, $client->email, $client->currency, $client->credit_balance]);
        $this->assertNull($client->password);
        $this->assertNotNull($client->legacy_password);
        $vat = CustomField::where('name', 'VAT number')->sole();
        $this->assertSame([(string) $vat->id => 'GB123'], $client->custom_fields);
        $this->assertSame(1250, $client->creditEntries()->sole()->balance_after);
        $contact = $client->contacts()->sole();
        $this->assertTrue($contact->invoice_emails);
        $this->assertFalse($contact->support_emails);

        $product = Product::sole();
        $this->assertSame("Fast\nCheap", $product->description);
        $this->assertEqualsCanonicalizing(['monthly' => 1000, 'annually' => 10000], $product->prices()->pluck('price', 'billing_cycle')->all());
        $this->assertSame(500, $product->prices()->where('billing_cycle', 'monthly')->value('setup_fee'));
        $option = $product->options()->sole();
        $this->assertSame(['Memory', ['1 GB', '2 GB']], [$option->name, $option->choices()->pluck('name')->all()]);

        $service = Service::findOrFail(40);
        $this->assertSame(['vps1.example.com', 1300, '2026-11-15'], [$service->label, $service->recurring_amount, $service->next_due_date->toDateString()]);
        $this->assertSame('2 GB', $service->options()->sole()->choice->name);
        $this->assertSame(300, $service->options()->sole()->unit_price);
        $this->assertSame(['Operating system' => 'Debian'], $service->customFieldsByName());

        $paid = Invoice::findOrFail(100);
        $this->assertSame([InvoiceStatus::Paid, 1300, 0], [$paid->status, $paid->total, $paid->balance()]);
        $payment = $paid->transactions()->sole();
        $this->assertSame(['paypal', 'PAY-1', 68], [$payment->gateway, $payment->gateway_reference, $payment->fee]);

        $renewal = Invoice::findOrFail(101);
        $this->assertSame(['101', 1300, 1000], [$renewal->number, $renewal->total, $renewal->balance()]);
        $this->assertStringContainsString('INV-0042', $renewal->notes);
        $this->assertSame('2026-12-14', $renewal->items()->sole()->period_end->toDateString());

        $ticket = Ticket::findOrFail(60);
        $this->assertSame(['482913', 'answered', 'high'], [$ticket->whmcs_tid, $ticket->status->value, $ticket->priority->value]);
        [$first, $reply] = $ticket->messages()->orderBy('id')->get()->all();
        $this->assertSame(["It's down", 'Ada O\'Neil'], [$first->body, $first->authorName()]);
        $this->assertSame(['Fixed it.', 'Jane Admin', true], [$reply->body, $reply->authorName(), $reply->fromStaff()]);

        $this->assertSame(101, json_decode(Setting::findOrFail(WhmcsImporter::SETTING)->value, true)['last_invoice_id']);
    }

    public function test_the_billing_run_does_not_invoice_a_period_whmcs_already_invoiced(): void
    {
        Mail::fake();
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        $generator = app(InvoiceGenerator::class);

        $this->assertCount(0, $generator->generate(Carbon::parse('2026-11-10')));

        // With that renewal cancelled, the period is invoiced again, once, numbered after the imported invoices.
        Invoice::findOrFail(101)->forceFill(['status' => InvoiceStatus::Cancelled])->save();
        $invoice = $generator->generate(Carbon::parse('2026-11-10'))->sole();
        $this->assertGreaterThan(101, $invoice->id);
        $this->assertSame((string) $invoice->id, $invoice->number);
        $this->assertSame(1300, $invoice->items()->sole()->amount);
        $this->assertCount(0, $generator->generate(Carbon::parse('2026-11-11')));
    }

    public function test_it_refuses_an_install_that_already_has_data(): void
    {
        Client::factory()->create();

        $this->expectException(ImportException::class);
        (new WhmcsImporter)->run($this->whmcs, dryRun: true);
    }

    public function test_records_it_cannot_bring_over_are_reported(): void
    {
        $this->whmcs->table('tblclients')->insert(['id' => 8, 'firstname' => 'Dup', 'lastname' => 'Licate', 'email' => 'ADA@example.com', 'status' => 'Active']);
        $this->whmcs->table('tblhosting')->insert(['id' => 41, 'userid' => 7, 'packageid' => 99, 'domain' => 'gone.example.com', 'billingcycle' => 'Monthly', 'domainstatus' => 'Active', 'amount' => '5.00']);
        $this->whmcs->table('tbltickets')->insert(['id' => 61, 'tid' => '111111', 'did' => 2, 'userid' => 0, 'title' => 'Guest question', 'message' => 'Hi', 'status' => 'Open', 'urgency' => 'Low']);

        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: true);

        $this->assertSame(1, $report->counts()['Clients']['skipped']);
        $this->assertSame(1, $report->counts()['Services']['skipped']);
        $this->assertSame(1, $report->counts()['Tickets']['skipped']);
        $this->assertStringContainsString('same email as client 7', $report->toMarkdown());
        $this->assertStringContainsString('uses product 99', $report->toMarkdown());
        $this->assertContains('Recurring amount of active and suspended services (USD)', array_column($report->mismatches(), 'label'));
    }

    public function test_add_ons_domains_tax_and_per_cycle_setup_fees_are_reported(): void
    {
        $this->whmcs->table('tblhostingaddons')->insert(['hostingid' => 40, 'name' => 'Backups', 'recurring' => '2.00', 'billingcycle' => 'Monthly', 'status' => 'Active']);
        $this->whmcs->table('tbldomains')->insert(['userid' => 7, 'domain' => 'ada.example', 'recurringamount' => '12.00', 'nextduedate' => '2027-01-01', 'status' => 'Active']);
        $this->whmcs->table('tblinvoices')->where('id', 101)->update(['taxrate' => '8.25']);
        $this->whmcs->table('tblpricing')->where('type', 'configoptions')->update(['msetupfee' => '1.00', 'asetupfee' => '5.00']);

        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: true);
        $markdown = $report->toMarkdown();

        $this->assertSame(['found' => 1, 'imported' => 0, 'skipped' => 1], $report->counts()['Service add-ons']);
        $this->assertSame(['found' => 1, 'imported' => 0, 'skipped' => 1], $report->counts()['Domains']);
        $this->assertStringContainsString('no tax rules here yet', $markdown);
        $this->assertStringContainsString('different setup fee per billing cycle', $markdown);
    }

    public function test_ticket_attachments_come_over_from_the_attachments_folder(): void
    {
        Storage::fake('local');
        $folder = sys_get_temp_dir().'/whmcs-attachments-'.uniqid();
        mkdir($folder);
        file_put_contents("{$folder}/123456_screen.png", 'png bytes');

        (new WhmcsImporter($folder))->run($this->whmcs, dryRun: false);

        $attachment = TicketAttachment::sole();
        $this->assertSame('screen.png', $attachment->name);
        Storage::disk('local')->assertExists($attachment->path);

        unlink("{$folder}/123456_screen.png");
        rmdir($folder);
    }

    public function test_the_command_writes_a_report(): void
    {
        $path = sys_get_temp_dir().'/whmcs-report-'.uniqid().'.md';

        $this->artisan('whmcs:import', ['--dry-run' => true, '--report' => $path])
            ->expectsOutputToContain('All totals match.')
            ->assertSuccessful();

        $this->assertStringContainsString('# WHMCS import dry run', (string) file_get_contents($path));
        $this->assertSame(0, Client::count());
        unlink($path);
    }

    public function test_comparing_needs_an_import_first(): void
    {
        $this->artisan('whmcs:compare')->expectsOutputToContain('Nothing has been imported from WHMCS yet')->assertFailed();
    }

    public function test_clients_log_in_with_their_whmcs_password_once_and_it_is_rehashed(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $this->post(self::LOGIN, ['email' => self::EMAIL, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest('client');

        $this->post(self::LOGIN, ['email' => self::EMAIL, 'password' => 'old-secret'])->assertRedirect('/portal');
        $this->assertAuthenticatedAs(Client::find(7), 'client');

        $client = Client::find(7);
        $this->assertNull($client->legacy_password);
        $this->assertTrue(password_verify('old-secret', $client->password));

        $this->post('/portal/logout');
        $this->post(self::LOGIN, ['email' => self::EMAIL, 'password' => 'old-secret'])->assertRedirect('/portal');
    }

    public function test_setting_a_new_password_drops_the_whmcs_one(): void
    {
        $client = Client::factory()->create(['password' => null]);
        $client->forceFill(['legacy_password' => password_hash('old-secret', PASSWORD_BCRYPT)])->save();

        $client->forceFill(['password' => 'a-new-password'])->save();

        $this->assertNull($client->fresh()->legacy_password);
    }

    public function test_only_modern_whmcs_hashes_are_kept(): void
    {
        $this->assertTrue(LegacyPassword::verify('pw', password_hash('pw', PASSWORD_BCRYPT)));
        $this->assertFalse(LegacyPassword::verify('nope', password_hash('pw', PASSWORD_BCRYPT)));
        $this->assertFalse(LegacyPassword::verify('pw', '5f4dcc3b5aa765d61d8327deb882cf99:salt'));
        $this->assertFalse(LegacyPassword::verify('pw', null));

        $this->whmcs->table('tblusers')->delete();
        $this->whmcs->table('tblclients')->where('id', 7)->update(['password' => '5f4dcc3b5aa765d61d8327deb882cf99:salt']);
        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $this->assertNull(Client::findOrFail(7)->legacy_password);
        $this->assertStringContainsString('Forgot password', $report->toMarkdown());
    }

    public function test_whmcs_sub_accounts_and_users_keep_their_portal_login(): void
    {
        $this->whmcs->table('tblcontacts')->insert([
            ['userid' => 7, 'firstname' => 'Dee', 'lastname' => 'Dev', 'email' => 'dev@example.com', 'subaccount' => '1', 'permissions' => 'profile,products,productsso,tickets', 'invoiceemails' => '0', 'supportemails' => '1'],
            ['userid' => 7, 'firstname' => 'Same', 'lastname' => 'Owner', 'email' => self::EMAIL, 'subaccount' => '1', 'permissions' => 'invoices', 'invoiceemails' => '0', 'supportemails' => '0'],
        ]);
        // WHMCS 8 keeps other people's logins as users of the client.
        $this->whmcs->table('tblusers')->insert([
            ['id' => 2, 'first_name' => 'Fin', 'last_name' => 'Ance', 'email' => 'Fin@Example.com', 'password' => 'x'],
            ['id' => 3, 'first_name' => 'Bob', 'last_name' => 'Books', 'email' => 'bob@example.com', 'password' => 'x'],
        ]);
        $this->whmcs->table('tblusers_clients')->insert([
            ['auth_user_id' => 2, 'client_id' => 7, 'owner' => 0, 'permissions' => 'invoices,orders'],
            ['auth_user_id' => 3, 'client_id' => 7, 'owner' => 0, 'permissions' => 'invoices'],
        ]);

        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        $contacts = Client::sole()->contacts->keyBy('email');

        $this->assertSame([true, ['services', 'support']], [$contacts['dev@example.com']->portal_access, $contacts['dev@example.com']->permissions]);
        $this->assertSame([true, ['billing', 'orders']], [$contacts['fin@example.com']->portal_access, $contacts['fin@example.com']->permissions]);
        // An existing contact who is also a WHMCS user gets the login.
        $this->assertSame([true, ['billing']], [$contacts['bob@example.com']->portal_access, $contacts['bob@example.com']->permissions]);
        // A contact with the owner's email can't sign in as well.
        $this->assertFalse($contacts[self::EMAIL]->portal_access);
        $this->assertStringContainsString('a client signs in with that email', $report->toMarkdown());
        $this->assertTrue(Client::sole()->hasVerifiedEmail());
    }

    public function test_a_whmcs_user_of_several_clients_gets_one_login_for_all_of_them(): void
    {
        $this->whmcs->table('tblclients')->insert([
            'id' => 8, 'firstname' => 'Grace', 'lastname' => 'Hopper', 'companyname' => 'Navy', 'email' => 'grace@example.com',
            'country' => 'US', 'status' => 'Active', 'currency' => 1, 'password' => '', 'datecreated' => '2021-01-02',
        ]);
        $this->whmcs->table('tblusers')->insert(['id' => 2, 'first_name' => 'Fin', 'last_name' => 'Ance', 'email' => 'fin@example.com', 'password' => 'x']);
        $this->whmcs->table('tblusers_clients')->insert([
            ['auth_user_id' => 2, 'client_id' => 7, 'owner' => 0, 'permissions' => 'invoices'],
            ['auth_user_id' => 2, 'client_id' => 8, 'owner' => 0, 'permissions' => 'tickets'],
        ]);

        (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $contacts = ClientContact::where('email', 'fin@example.com')->orderBy('client_id')->get();
        $this->assertSame([[7, ['billing']], [8, ['support']]], $contacts->map(fn ($contact) => [$contact->client_id, $contact->permissions])->all());
        $this->assertTrue($contacts->every->portal_access);

        // One Forgot password sets the login for both accounts.
        $contacts[0]->forceFill(['password' => 'fin-password-1'])->save();
        $this->assertSame($contacts[0]->fresh()->password, $contacts[1]->fresh()->password);
    }

    public function test_client_groups_notes_to_dos_and_marketing_consent_come_over(): void
    {
        $this->whmcs->table('tblclientgroups')->insert([
            ['id' => 3, 'groupname' => 'Resellers', 'groupcolour' => '#AA00FF', 'discountpercent' => 10, 'susptermexempt' => 'on', 'separateinvoices' => ''],
        ]);
        $this->whmcs->table('tblclients')->where('id', 7)->update(['groupid' => 3, 'marketing_emails_opt_in' => 1]);
        $this->whmcs->table('tbladmins')->insert(['id' => 1, 'email' => self::JANE, 'firstname' => 'Jane', 'lastname' => 'Admin']);
        $this->whmcs->table('tblnotes')->insert([
            ['userid' => 7, 'adminid' => 1, 'created' => self::NOTE_TIME, 'modified' => '2024-03-02 10:00:00', 'note' => 'Prefers &quot;email&quot;', 'sticky' => 1],
            ['userid' => 99, 'adminid' => 1, 'created' => self::NOTE_TIME, 'modified' => self::NOTE_TIME, 'note' => 'Orphan', 'sticky' => 0],
        ]);
        $this->whmcs->table('tbltodolist')->insert([
            ['date' => '2024-04-01', 'title' => 'Renew certificate', 'description' => 'Wildcard', 'admin' => 1, 'status' => 'In Progress', 'duedate' => '2024-05-01'],
            ['date' => '2024-04-02', 'title' => 'Old job', 'description' => '', 'admin' => 0, 'status' => 'Completed', 'duedate' => '0000-00-00'],
        ]);

        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        $client = Client::sole();

        $this->assertSame(['Resellers', '#aa00ff'], [$client->group->name, $client->group->colour]);
        $this->assertSame([1000, false, true, false], [$client->group->discount_percent, $client->group->discount_renewals, $client->group->exempt_suspension, $client->group->separate_invoices]);
        $this->assertStringContainsString('Group Resellers gets its 10% discount on new orders', $report->toMarkdown());
        $this->assertTrue($client->marketing_emails);

        $note = $client->clientNotes()->sole();
        $this->assertSame(['Prefers "email"', true, self::JANE], [$note->body, $note->pinned, $note->user->email]);
        $this->assertSame(['found' => 2, 'imported' => 1, 'skipped' => 1], $report->counts()['Client notes']);

        $todos = Todo::orderBy('id')->get();
        $this->assertSame([Todo::IN_PROGRESS, '2024-05-01', self::JANE], [$todos[0]->status, $todos[0]->due_on->toDateString(), $todos[0]->user->email]);
        $this->assertSame([Todo::DONE, null, null], [$todos[1]->status, $todos[1]->due_on, $todos[1]->user]);
    }

    public function test_replies_to_old_whmcs_ticket_emails_find_the_ticket(): void
    {
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $email = "From: ada@example.com\r\nTo: support@example.com\r\nSubject: Re: [Ticket ID: 482913] Server down\r\nMessage-ID: <x1@example.com>\r\nContent-Type: text/plain\r\n\r\nStill down.\r\n";
        [$outcome] = app(InboundMail::class)->handle($email);

        $this->assertSame(InboundMail::REPLIED, $outcome);
        $this->assertSame('Still down.', Ticket::findOrFail(60)->messages()->reorder()->latest('id')->first()->body);
    }
}
