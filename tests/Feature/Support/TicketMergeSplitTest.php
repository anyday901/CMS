<?php

namespace Tests\Feature\Support;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\EmailLog;
use App\Models\Ticket;
use App\Models\TicketTag;
use App\Models\User;
use App\Tickets\InboundMail;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketMergeSplitTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Client $client;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->travelTo('2026-10-14 09:00');
        $this->department = Department::create(['name' => 'Technical', 'email' => 'support@example.com', 'active' => true]);
        $this->client = Client::factory()->create(['email' => 'ada@example.com']);
        $this->staff = User::factory()->create();
    }

    private function open(string $subject, string $body, ?Client $client = null): Ticket
    {
        return app(TicketDesk::class)->open($client ?? $this->client, $this->department, $subject, $body);
    }

    public function test_staff_merge_tickets_of_the_same_client(): void
    {
        $first = $this->open('Site down', 'My site is down');
        $this->travel(1)->hour();
        $second = $this->open('Still down', 'Any news?');
        $second->syncTags(['outage']);
        $this->travel(1)->hour();
        app(TicketDesk::class)->staffReply($second, $this->staff, 'Fixed now');

        $this->actingAs($this->staff, 'web')
            ->get(route('admin.tickets.show', $first))->assertOk()->assertSee('Merge into this ticket')->assertSee("#{$second->id} Still down");
        $this->post(route('admin.tickets.merge', $first), ['tickets' => [$second->id]])->assertRedirect(route('admin.tickets.show', $first));

        $first->refresh();
        $second->refresh();
        $this->assertSame([$first->id, TicketStatus::Closed], [$second->merged_into_id, $second->status]);
        $this->assertSame(0, $second->messages()->count());
        $this->assertSame(['My site is down', 'Any news?', 'Fixed now', "Merged #{$second->id} (Still down) into this ticket."], $first->messages->pluck('body')->all());
        $this->assertTrue($first->messages->last()->internal);
        $this->assertEquals(now(), $first->last_reply_at);
        // Staff wrote last, so the merged ticket waits on the client.
        $this->assertSame(TicketStatus::Answered, $first->status);
        $this->assertSame(['outage'], $first->tags->pluck('name')->all());

        // The merged ticket's number leads to the ticket it went into, for staff, the client and email replies.
        $this->get(route('admin.tickets.show', $second))->assertRedirect(route('admin.tickets.show', $first));
        $this->get('/admin/tickets?status=all')->assertSee('Site down')->assertDontSee('Still down');
        $this->actingAs($this->client, 'client')->get(route('portal.tickets.show', $second))->assertRedirect(route('portal.tickets.show', $first));

        $email = "From: ada@example.com\r\nTo: support@example.com\r\nSubject: Re: [Ticket #{$second->id}] Still down\r\n\r\nThanks!\r\n";
        [$outcome] = app(InboundMail::class)->handle($email);
        $this->assertSame(InboundMail::REPLIED, $outcome);
        $this->assertSame('Thanks!', $first->messages()->get()->last()->body);
    }

    public function test_tickets_of_another_client_cannot_be_merged(): void
    {
        $mine = $this->open('Mine', 'Hello');
        $theirs = $this->open('Theirs', 'Hi', Client::factory()->create());

        $this->actingAs($this->staff, 'web')
            ->post(route('admin.tickets.merge', $mine), ['tickets' => [$theirs->id]])
            ->assertSessionHasErrors(['tickets' => "#{$theirs->id} belongs to another client."]);
        $this->assertNull($theirs->fresh()->merged_into_id);
        $this->get(route('admin.tickets.show', $mine))->assertDontSee("#{$theirs->id} Theirs");
    }

    public function test_staff_split_messages_into_a_new_ticket(): void
    {
        $ticket = $this->open('Two problems', 'Email is broken');
        $this->travel(5)->minutes();
        $second = app(TicketDesk::class)->clientReply($ticket, 'Also, please change my invoice address');
        $ticket->syncTags(['vip']);

        $this->actingAs($this->staff, 'web')->get(route('admin.tickets.show', $ticket))->assertOk()->assertSee('Split into a new ticket');
        $this->post(route('admin.tickets.split', $ticket), [
            'messages' => [$second->id],
            'split_subject' => 'Invoice address',
            'split_department_id' => $this->department->id,
            'split_priority' => 'low',
            'notify' => '1',
        ])->assertRedirect();

        $new = Ticket::latest('id')->first();
        $this->assertNotSame($ticket->id, $new->id);
        $this->assertSame(['Invoice address', TicketStatus::Open, TicketPriority::Low, $this->client->id], [$new->subject, $new->status, $new->priority, $new->client_id]);
        $this->assertSame(['Also, please change my invoice address', "Split from ticket #{$ticket->id} (Two problems)."], $new->messages->pluck('body')->all());
        $this->assertSame(['vip'], $new->tags->pluck('name')->all());
        $this->assertSame('Email is broken', $ticket->messages()->first()->body);
        $this->assertSame("Moved 1 message(s) to ticket #{$new->id} (Invoice address).", $ticket->messages()->get()->last()->body);
        $this->assertSame(1, EmailLog::where('template', 'ticket_opened')->where('ticket_id', $new->id)->count());
    }

    public function test_a_split_recomputes_who_the_source_ticket_waits_on(): void
    {
        $ticket = $this->open('Two problems', 'Email is broken');
        $desk = app(TicketDesk::class);
        $desk->staffReply($ticket, $this->staff, 'Looking at email');
        $this->travel(5)->minutes();
        $desk->clientReply($ticket->fresh(), 'Also, my invoice is wrong');
        $this->travel(5)->minutes();
        $answer = $desk->staffReply($ticket->fresh(), $this->staff, 'Invoice fixed');
        $this->assertSame(TicketStatus::Answered, $ticket->fresh()->status);

        $this->actingAs($this->staff, 'web')->post(route('admin.tickets.split', $ticket), [
            'messages' => [$answer->id],
            'split_subject' => 'Invoice',
            'split_department_id' => $this->department->id,
            'split_priority' => 'low',
        ])->assertRedirect();

        // The client wrote last on what's left, so the ticket waits on staff again.
        $this->assertSame(TicketStatus::CustomerReply, $ticket->fresh()->status);
        $this->assertSame(TicketStatus::Answered, Ticket::latest('id')->first()->status);
    }

    public function test_internal_notes_alone_cannot_be_split_off(): void
    {
        $ticket = $this->open('One problem', 'Email is broken');
        $note = app(TicketDesk::class)->staffReply($ticket, $this->staff, 'Check the MX record', internal: true);

        $this->actingAs($this->staff, 'web')->post(route('admin.tickets.split', $ticket), [
            'messages' => [$note->id],
            'split_subject' => 'Notes',
            'split_department_id' => $this->department->id,
            'split_priority' => 'low',
        ])->assertSessionHasErrors('messages');
        $this->assertSame(1, Ticket::count());
    }

    public function test_a_split_leaves_at_least_one_message(): void
    {
        $ticket = $this->open('One problem', 'Email is broken');
        $reply = app(TicketDesk::class)->staffReply($ticket, $this->staff, 'Looking');

        $this->actingAs($this->staff, 'web')->post(route('admin.tickets.split', $ticket), [
            'messages' => $ticket->messages()->pluck('id')->all(),
            'split_subject' => 'All of it',
            'split_department_id' => $this->department->id,
            'split_priority' => 'low',
        ])->assertSessionHasErrors('messages');
        $this->assertSame(1, Ticket::count());

        $this->post(route('admin.tickets.split', $ticket), [
            'messages' => [$reply->id + 100],
            'split_subject' => 'Not here',
            'split_department_id' => $this->department->id,
            'split_priority' => 'low',
        ])->assertSessionHasErrors(['messages' => 'Pick the messages to move from this ticket.']);
    }

    public function test_staff_tag_tickets_and_filter_by_tag(): void
    {
        $billing = $this->open('Wrong amount', 'Invoice is wrong');
        $outage = $this->open('Site down', 'Down');

        $this->actingAs($this->staff, 'web')->put(route('admin.tickets.update', $billing), [
            'status' => 'open', 'priority' => 'medium', 'department_id' => $this->department->id, 'tags' => ' Billing, refund ,billing,,',
        ])->assertRedirect();
        $this->assertSame(['billing', 'refund'], $billing->fresh()->tags->pluck('name')->all());

        $this->get('/admin/tickets?tag=billing')->assertOk()->assertSee('Wrong amount')->assertDontSee('Site down');
        $this->get('/admin/tickets')->assertSee('Wrong amount')->assertSee('Site down')->assertSee('refund');

        // Tags no ticket uses any more are tidied away.
        $this->put(route('admin.tickets.update', $billing), ['status' => 'open', 'priority' => 'medium', 'department_id' => $this->department->id, 'tags' => 'billing']);
        $this->assertSame(['billing'], TicketTag::pluck('name')->all());
        $this->assertSame([], $outage->tags->pluck('name')->all());
    }
}
