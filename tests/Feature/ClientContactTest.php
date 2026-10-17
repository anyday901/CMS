<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Events\InvoiceIssued;
use App\Mail\LoggedEmail;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Department;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\User;
use App\Tickets\InboundMail;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientContactTest extends TestCase
{
    use RefreshDatabase;

    private const SUPPORT_EMAIL = 'support@example.com';

    private const BOOKKEEPER = 'books@example.com';

    private const DEVELOPER = 'dev@example.com';

    private const ACCOUNT = '/portal/account';

    private function contact(Client $client, string $email, array $flags): ClientContact
    {
        return $client->contacts()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => $email] + $flags);
    }

    public function test_staff_add_edit_and_remove_contacts(): void
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->create(), 'web');

        $this->get("/admin/clients/{$client->id}/contacts/create")->assertOk();
        $this->post("/admin/clients/{$client->id}/contacts", [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::BOOKKEEPER, 'invoice_emails' => '1',
        ])->assertRedirect("/admin/clients/{$client->id}?tab=contacts");

        $contact = ClientContact::sole();
        $this->assertSame([true, false], [$contact->invoice_emails, $contact->support_emails]);
        $this->get("/admin/clients/{$client->id}")->assertSee(self::BOOKKEEPER)->assertSee('Invoice emails');

        $this->post("/admin/clients/{$client->id}/contacts", ['first_name' => 'A', 'last_name' => 'B', 'email' => $client->email])
            ->assertSessionHasErrors('email');

        $this->put("/admin/contacts/{$contact->id}", ['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::BOOKKEEPER, 'support_emails' => '1'])->assertRedirect();
        $this->assertSame([false, true], [$contact->fresh()->invoice_emails, $contact->fresh()->support_emails]);

        $this->delete("/admin/contacts/{$contact->id}")->assertRedirect();
        $this->assertSame(0, ClientContact::count());
    }

    public function test_support_staff_cannot_change_contacts(): void
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');

        $this->post("/admin/clients/{$client->id}/contacts", ['first_name' => 'A', 'last_name' => 'B', 'email' => self::DEVELOPER])->assertForbidden();
    }

    public function test_clients_manage_their_own_contacts_only(): void
    {
        $client = Client::factory()->create();
        $other = $this->contact(Client::factory()->create(), self::DEVELOPER, []);
        $this->actingAs($client, 'client');

        $this->post('/portal/account/contacts', ['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::BOOKKEEPER, 'invoice_emails' => '1'])
            ->assertRedirect(self::ACCOUNT);
        $this->get(self::ACCOUNT)->assertSee(self::BOOKKEEPER);
        $mine = $client->contacts()->sole();

        $this->get("/portal/account/contacts/{$mine->id}/edit")->assertOk();
        $this->get("/portal/account/contacts/{$other->id}/edit")->assertNotFound();
        $this->put("/portal/account/contacts/{$other->id}", ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com'])->assertNotFound();
        $this->delete("/portal/account/contacts/{$other->id}")->assertNotFound();

        $this->delete("/portal/account/contacts/{$mine->id}")->assertRedirect(self::ACCOUNT);
        $this->assertSame([0, 1], [$client->contacts()->count(), ClientContact::count()]);
    }

    public function test_contacts_are_copied_on_the_emails_they_asked_for(): void
    {
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        $client = Client::factory()->create();
        $this->contact($client, self::BOOKKEEPER, ['invoice_emails' => true]);
        $this->contact($client, self::DEVELOPER, ['support_emails' => true]);

        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 1000]);
        InvoiceIssued::dispatch($invoice->recalculate());

        $this->assertSame([self::BOOKKEEPER], EmailLog::sole()->cc);
        Mail::assertSent(LoggedEmail::class, fn (LoggedEmail $mail) => $mail->hasTo($client->email) && $mail->hasCc(self::BOOKKEEPER) && ! $mail->hasCc(self::DEVELOPER));

        $client->sendPasswordResetNotification('token');
        $this->assertNull(EmailLog::where('template', 'password_reset')->sole()->cc);

        $department = Department::create(['name' => 'Technical', 'email' => self::SUPPORT_EMAIL, 'active' => true]);
        $this->actingAs($client, 'client')->post('/portal/support', ['department_id' => $department->id, 'subject' => 'Help', 'priority' => 'low', 'body' => 'Hi']);
        $this->assertSame([self::DEVELOPER], EmailLog::where('template', 'ticket_opened')->sole()->cc);
    }

    public function test_contacts_who_get_support_emails_can_open_tickets_by_email(): void
    {
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        Department::create(['name' => 'Technical', 'email' => self::SUPPORT_EMAIL, 'active' => true]);
        $client = Client::factory()->create();
        $this->contact($client, self::DEVELOPER, ['support_emails' => true]);
        $this->contact($client, self::BOOKKEEPER, ['invoice_emails' => true]);
        $email = fn (string $from) => "From: {$from}\r\nTo: support@example.com\r\nSubject: Site down\r\nMessage-ID: <".uniqid('', true)."@example.com>\r\nContent-Type: text/plain\r\n\r\nPlease help.\r\n";

        [$outcome] = app(InboundMail::class)->handle($email('Dev@Example.com'));
        $this->assertSame(InboundMail::OPENED, $outcome);
        $this->assertSame($client->id, Ticket::sole()->client_id);

        [$outcome] = app(InboundMail::class)->handle($email(self::BOOKKEEPER));
        $this->assertSame(InboundMail::IGNORED, $outcome);
    }

    public function test_an_address_that_is_a_contact_for_several_clients_follows_the_ticket(): void
    {
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        Department::create(['name' => 'Technical', 'email' => self::SUPPORT_EMAIL, 'active' => true]);
        [$first, $second] = Client::factory()->count(2)->create();
        $this->contact($first, self::DEVELOPER, ['support_emails' => true]);
        $this->contact($second, self::DEVELOPER, ['support_emails' => true]);
        $email = fn (string $subject) => 'From: '.self::DEVELOPER."\r\nTo: support@example.com\r\nSubject: {$subject}\r\nMessage-ID: <".uniqid('', true)."@example.com>\r\nContent-Type: text/plain\r\n\r\nPlease help.\r\n";

        // A new ticket can't tell which client it's for.
        [$outcome] = app(InboundMail::class)->handle($email('Site down'));
        $this->assertSame(InboundMail::IGNORED, $outcome);

        // A reply goes to the client whose ticket it is.
        $department = Department::sole();
        $ticket = app(TicketDesk::class)->open($second, $department, 'Site down', 'Help');
        [$outcome] = app(InboundMail::class)->handle($email("Re: [Ticket #{$ticket->id}] Site down"));
        $this->assertSame(InboundMail::REPLIED, $outcome);
        $this->assertSame(2, $ticket->messages()->count());
    }
}
