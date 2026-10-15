<?php

namespace Tests\Feature\Support;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\EmailLog;
use App\Models\InboundEmail;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Tickets\InboundMail;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GuestTicketTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST = 'sam@visitor.example';

    private const SUBJECT = 'Do you offer Windows VPS?';

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        config(['tickets.guest_tickets' => true]);
        $this->department = Department::create(['name' => 'Sales', 'email' => 'sales@example.com', 'active' => true]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function submit(array $overrides = []): TestResponse
    {
        return $this->post(route('tickets.guest.store'), $overrides + [
            'name' => 'Sam Visitor',
            'email' => 'Sam@Visitor.example',
            'department_id' => $this->department->id,
            'priority' => 'medium',
            'subject' => self::SUBJECT,
            'body' => 'And how much?',
        ]);
    }

    private function email(string $subject, string $from = 'Sam Visitor <sam@visitor.example>', string $extra = ''): string
    {
        return "From: {$from}\r\nTo: sales@example.com\r\nSubject: {$subject}\r\nMessage-ID: <".uniqid('', true)."@visitor.example>\r\n{$extra}Content-Type: text/plain; charset=UTF-8\r\n\r\nHello there\r\n";
    }

    public function test_the_contact_form_opens_a_guest_ticket_and_emails_the_private_link(): void
    {
        $this->get(route('tickets.guest.create', ['department' => $this->department->id]))->assertOk()->assertSee('Contact support');

        $response = $this->submit()->assertOk()->assertSee('We\'ve emailed <strong>sam@visitor.example</strong>', false);

        $ticket = Ticket::sole();
        $this->assertTrue($ticket->isGuest());
        $this->assertSame(['Sam Visitor', self::GUEST], [$ticket->guest_name, $ticket->guest_email]);
        $this->assertSame(40, strlen($ticket->access_key));
        // Only the inbox gets the link, so whoever typed the address can't read the ticket without it.
        $response->assertDontSee($ticket->access_key);

        $message = TicketMessage::sole();
        $this->assertTrue($message->guest);
        $this->assertFalse($message->fromStaff());
        $this->assertSame('Sam Visitor', $message->authorName());

        $email = EmailLog::where('template', 'ticket_opened')->sole();
        $this->assertSame([self::GUEST, null], [$email->to, $email->client_id]);
        $this->assertStringContainsString($ticket->guestUrl(), $email->body);
        $this->assertStringContainsString('Hello Sam,', $email->body);
    }

    public function test_the_contact_form_is_off_unless_guest_tickets_are_on(): void
    {
        config(['tickets.guest_tickets' => false]);

        $this->get(route('tickets.guest.create'))->assertRedirect(route('portal.tickets.create'));
        $this->submit()->assertRedirect(route('portal.tickets.create'));
        $this->assertSame(0, Ticket::count());
    }

    public function test_logged_in_clients_use_the_portal_form(): void
    {
        $this->actingAs(Client::factory()->create(), 'client')->get(route('tickets.guest.create'))->assertRedirect(route('portal.tickets.create'));
    }

    public function test_the_private_link_shows_the_ticket_and_takes_replies(): void
    {
        $this->submit();
        $ticket = Ticket::sole();
        app(TicketDesk::class)->staffReply($ticket, User::factory()->create(['name' => 'Jane Staff']), 'We do, from $10.');

        $this->get($ticket->guestUrl())->assertOk()->assertSee('We do, from $10.')->assertSee('Jane Staff');
        $this->get(route('tickets.guest.show', [$ticket, str_repeat('x', 40)]))->assertNotFound();
        $this->assertSame(self::GUEST, EmailLog::where('template', 'ticket_reply')->sole()->to);

        $this->post(route('tickets.guest.reply', [$ticket, $ticket->access_key]), ['body' => 'Great, thanks'])->assertRedirect($ticket->guestUrl());
        $this->assertSame(TicketStatus::CustomerReply, $ticket->fresh()->status);
        $this->assertTrue(TicketMessage::latest('id')->first()->guest);

        $this->post(route('tickets.guest.close', [$ticket, $ticket->access_key]))->assertRedirect($ticket->guestUrl());
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    public function test_a_guest_can_rate_a_closed_ticket_through_the_link(): void
    {
        $this->department->update(['feedback_requests' => true]);
        $this->submit();
        $ticket = Ticket::sole();
        app(TicketDesk::class)->close($ticket, User::factory()->create());

        $this->assertStringContainsString($ticket->guestUrl().'#feedback', EmailLog::where('template', 'ticket_feedback')->sole()->body);
        $this->post(route('tickets.guest.feedback', [$ticket, $ticket->access_key]), ['rating' => 4])->assertRedirect($ticket->guestUrl());
        $this->assertSame([4, null], [$ticket->feedback->rating, $ticket->feedback->client_id]);
    }

    public function test_an_email_from_someone_without_an_account_opens_a_guest_ticket_and_their_reply_adds_to_it(): void
    {
        [$outcome] = app(InboundMail::class)->handle($this->email(self::SUBJECT));
        $this->assertSame(InboundMail::OPENED, $outcome);

        $ticket = Ticket::sole();
        $this->assertSame(['Sam Visitor', self::GUEST], [$ticket->guest_name, $ticket->guest_email]);

        [$outcome] = app(InboundMail::class)->handle($this->email("Re: [Ticket #{$ticket->id}] Windows"));
        $this->assertSame(InboundMail::REPLIED, $outcome);
        $this->assertSame(2, $ticket->messages()->count());

        // Someone else naming the ticket gets a ticket of their own, not a reply on Sam's.
        [$outcome] = app(InboundMail::class)->handle($this->email("Re: [Ticket #{$ticket->id}] Windows", 'Eve <eve@elsewhere.example>'));
        $this->assertSame(InboundMail::OPENED, $outcome);
        $this->assertSame(2, $ticket->messages()->count());
        $this->assertSame(2, Ticket::count());
    }

    public function test_bounces_and_our_own_addresses_never_open_guest_tickets(): void
    {
        config(['mail.from.address' => 'billing@example.com']);

        foreach (['MAILER-DAEMON@mx.example', 'Billing <billing@example.com>', 'Sales <sales@example.com>'] as $from) {
            [$outcome] = app(InboundMail::class)->handle($this->email('Undeliverable: '.self::SUBJECT, $from));
            $this->assertSame(InboundMail::IGNORED, $outcome, $from);
        }

        $this->assertSame(0, Ticket::count());
    }

    public function test_with_guest_tickets_off_strangers_can_still_reply_to_their_own_ticket_but_not_open_new_ones(): void
    {
        app(InboundMail::class)->handle($this->email(self::SUBJECT));
        $ticket = Ticket::sole();
        config(['tickets.guest_tickets' => false]);

        $this->assertSame(InboundMail::REPLIED, app(InboundMail::class)->handle($this->email("[Ticket #{$ticket->id}] Windows"))[0]);
        [$outcome, $reason] = app(InboundMail::class)->handle($this->email('Another question'));
        $this->assertSame(InboundMail::IGNORED, $outcome);
        $this->assertStringContainsString('is not a client', $reason);
        $this->assertSame(InboundMail::IGNORED, InboundEmail::latest('id')->first()->outcome);
    }

    public function test_staff_see_the_guest_and_can_move_the_ticket_to_a_client_account(): void
    {
        $this->submit();
        $ticket = Ticket::sole();
        $client = Client::factory()->create(['email' => self::GUEST, 'first_name' => 'Samantha']);
        $staff = User::factory()->create();
        $key = $ticket->access_key;

        $this->actingAs($staff, 'web')->get(route('admin.tickets.index'))->assertOk()->assertSee(self::GUEST);
        $this->get(route('admin.tickets.show', $ticket))->assertOk()->assertSee('From a guest')->assertSee('Samantha')->assertSee('Move to client');

        $this->post(route('admin.tickets.client', $ticket), ['client_email' => 'nobody@example.com'])->assertSessionHasErrors('client_email');
        $this->post(route('admin.tickets.client', $ticket), ['client_email' => self::GUEST])->assertRedirect(route('admin.tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame([$client->id, null, null], [$ticket->client_id, $ticket->guest_email, $ticket->access_key]);
        $this->assertSame($client->id, TicketMessage::sole()->client_id);
        $this->assertFalse(TicketMessage::sole()->guest);
        $this->get(route('tickets.guest.show', [$ticket, $key]))->assertNotFound();
    }

    public function test_only_tickets_from_the_same_guest_address_can_be_merged(): void
    {
        $desk = app(TicketDesk::class);
        $first = $desk->openForGuest('Sam', self::GUEST, $this->department, 'One', 'First');
        $second = $desk->openForGuest('Sam', 'SAM@visitor.example', $this->department, 'Two', 'Second');
        $other = $desk->openForGuest('Eve', 'eve@elsewhere.example', $this->department, 'Three', 'Third');
        $this->actingAs(User::factory()->create(), 'web');

        $this->get(route('admin.tickets.show', $first))->assertSee('#'.$second->id.' Two')->assertDontSee('#'.$other->id.' Three');
        $this->post(route('admin.tickets.merge', $first), ['tickets' => [$other->id]])->assertSessionHasErrors('tickets');
        $this->post(route('admin.tickets.merge', $first), ['tickets' => [$second->id]])->assertSessionHasNoErrors();

        // The merged ticket's link leads to the one it was merged into.
        $this->get($second->fresh()->guestUrl())->assertRedirect($first->fresh()->guestUrl());
    }
}
