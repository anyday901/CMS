<?php

namespace Tests\Feature\Admin;

use App\Billing\CreditLedger;
use App\Clients\ClientMerger;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientNote;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\LoginAttempt;
use App\Models\Service;
use App\Models\Todo;
use App\Models\User;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientMergeTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED = 'books@example.com';

    private const DUPLICATE = 'ada.old@example.com';

    private const PAT = 'pat@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->actingAs(User::factory()->create());
    }

    public function test_everything_on_a_duplicate_account_moves_to_the_one_kept(): void
    {
        $keep = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']);
        $old = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => self::DUPLICATE, 'phone' => '555-0100', 'notes' => 'Pays by check']);
        app(CreditLedger::class)->change($keep->id, 500, 'Goodwill');
        app(CreditLedger::class)->change($old->id, 1200, 'Overpayment');

        $service = Service::factory()->for($old)->create();
        $invoice = Invoice::create(['client_id' => $old->id, 'currency' => $old->currency, 'issue_date' => today(), 'due_date' => today()]);
        $ticket = app(TicketDesk::class)->open($old, Department::create(['name' => 'Support']), 'Old ticket', 'Hello');
        $keep->contacts()->create(['first_name' => 'Bob', 'last_name' => 'Books', 'email' => self::SHARED, 'invoice_emails' => true]);
        $oldBob = $old->contacts()->create(['first_name' => 'Bob', 'last_name' => 'Books', 'email' => self::SHARED, 'support_emails' => true, 'portal_access' => true, 'permissions' => ['support']]);
        $bobLogin = LoginAttempt::create(['area' => 'portal', 'email' => self::SHARED, 'account_type' => $oldBob->getMorphClass(), 'account_id' => $oldBob->id, 'client_id' => $old->id, 'outcome' => 'success', 'created_at' => now()]);
        $old->contacts()->create(['first_name' => 'Dev', 'last_name' => 'Eloper', 'email' => 'dev@example.com']);
        $old->clientNotes()->create(['body' => 'Old note']);
        $todo = Todo::create(['title' => 'Call Ada', 'client_id' => $old->id]);
        LoginAttempt::create(['area' => 'portal', 'email' => self::DUPLICATE, 'account_type' => $old->getMorphClass(), 'account_id' => $old->id, 'client_id' => $old->id, 'outcome' => 'success', 'created_at' => now()]);

        $this->get("/admin/clients/{$old->id}/merge?q=ada")->assertOk()->assertSee('ada@example.com');
        $this->get("/admin/clients/{$old->id}/merge?into={$keep->id}")->assertOk()->assertSee('Merge and delete #'.$old->id);

        $this->post("/admin/clients/{$old->id}/merge", ['into' => $keep->id])->assertSessionHasErrors('confirm');
        $this->post("/admin/clients/{$old->id}/merge", ['into' => $keep->id, 'confirm' => '1', 'keep_as_contact' => '1'])
            ->assertRedirect("/admin/clients/{$keep->id}");

        $this->assertNull(Client::find($old->id));
        $this->assertSame($keep->id, $service->fresh()->client_id);
        $this->assertSame($keep->id, $invoice->fresh()->client_id);
        $this->assertSame($keep->id, $ticket->fresh()->client_id);
        $this->assertSame($keep->id, $todo->fresh()->client_id);
        $this->assertSame([$keep->id], LoginAttempt::pluck('client_id')->unique()->values()->all());

        // Credit adds up, with a line in the history.
        $this->assertSame(1700, $keep->fresh()->credit_balance);
        $this->assertSame(1700, $keep->creditEntries()->latest('id')->first()->balance_after);

        // The same contact on both is folded into one; the account holder stays reachable as a contact.
        $contacts = $keep->fresh()->contacts->keyBy('email');
        $this->assertEqualsCanonicalizing([self::DUPLICATE, self::SHARED, 'dev@example.com'], $contacts->keys()->all());
        $bob = $contacts[self::SHARED];
        $this->assertTrue($bob->invoice_emails && $bob->support_emails && $bob->portal_access);
        $this->assertSame(['support'], $bob->permissions);
        $this->assertFalse($contacts[self::DUPLICATE]->portal_access);
        // Its sign-in history follows it.
        $this->assertSame($bob->id, $bobLogin->fresh()->account_id);

        // Its details are kept in a note, and the merge is logged.
        $note = ClientNote::where('body', 'like', 'Merged from client #%')->sole();
        $this->assertStringContainsString(self::DUPLICATE, $note->body);
        $this->assertStringContainsString('Pays by check', $note->body);
        $this->assertSame(2, $keep->clientNotes()->count());
        $this->assertNotNull(Activity::where('client_id', $keep->id)->where('description', 'like', "Merged client #{$old->id}%")->first());
        $this->get("/admin/clients/{$keep->id}")->assertOk()->assertSee('Old ticket');
    }

    public function test_clients_billed_in_different_currencies_cannot_be_merged(): void
    {
        $usd = Client::factory()->create(['currency' => 'USD']);
        $eur = Client::factory()->create(['currency' => 'EUR']);

        $this->get("/admin/clients/{$eur->id}/merge?into={$usd->id}")->assertSee('billed in EUR');
        $this->post("/admin/clients/{$eur->id}/merge", ['into' => $usd->id, 'confirm' => '1'])->assertSessionHasErrors('into');
        $this->post("/admin/clients/{$eur->id}/merge", ['into' => $eur->id, 'confirm' => '1'])->assertSessionHasErrors('into');
        $this->assertSame(2, Client::count());
    }

    public function test_every_table_with_a_client_is_handled_by_the_merge(): void
    {
        $tables = collect(Schema::getTables())->pluck('name')
            ->filter(fn (string $table) => Schema::hasColumn($table, 'client_id'))
            ->sort()->values()->all();

        $this->assertSame($tables, collect([...ClientMerger::MOVES, ...ClientMerger::HANDLED])->sort()->values()->all(),
            'A new table with a client_id needs adding to ClientMerger, so merging clients moves its rows.');
    }

    public function test_the_merged_account_can_no_longer_sign_in_but_contacts_can(): void
    {
        $keep = Client::factory()->create();
        $old = Client::factory()->create(['email' => self::DUPLICATE, 'password' => 'old-password-1']);
        $contact = $old->contacts()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::PAT, 'portal_access' => true, 'permissions' => ['billing']]);
        $contact->forceFill(['password' => 'pat-password-1'])->save();

        app(ClientMerger::class)->merge($old, $keep);
        auth()->guard('web')->logout();

        $this->post('/portal/login', ['email' => self::DUPLICATE, 'password' => 'old-password-1'])->assertSessionHasErrors('email');
        $this->post('/portal/login', ['email' => self::PAT, 'password' => 'pat-password-1'])->assertRedirect('/portal');
        $this->assertSame($keep->id, auth()->guard('client')->id());
        $this->assertSame(0, DB::table('client_contacts')->where('client_id', $old->id)->count());
        $this->assertTrue(ClientContact::where('email', self::PAT)->sole()->client->is($keep));
    }
}
