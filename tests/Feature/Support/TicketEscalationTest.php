<?php

namespace Tests\Feature\Support;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\EscalationRule;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketEscalationTest extends TestCase
{
    use RefreshDatabase;

    private const RULES = '/admin/escalation-rules';

    private const OPENED = '2026-10-14 09:00';

    private Department $technical;

    private Department $managers;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->travelTo(self::OPENED);
        $this->technical = Department::create(['name' => 'Technical', 'active' => true, 'sla_hours' => ['urgent' => 1, 'medium' => 8]]);
        $this->managers = Department::create(['name' => 'Managers', 'active' => true]);
    }

    private function open(TicketPriority $priority = TicketPriority::Urgent): Ticket
    {
        return app(TicketDesk::class)->open(Client::factory()->create(), $this->technical, 'Server down', 'Help', $priority);
    }

    public function test_tickets_get_a_response_due_time_from_the_departments_sla(): void
    {
        $ticket = $this->open();
        $this->assertEquals(now()->addHour(), $ticket->response_due_at);
        $this->assertNotNull($this->open(TicketPriority::Medium)->response_due_at);
        $this->assertNull($this->open(TicketPriority::Low)->response_due_at);

        $this->travel(2)->hours();
        $this->assertTrue($ticket->fresh()->responseOverdue());
        $this->assertSame([$ticket->id], Ticket::overdue()->pluck('id')->all());

        $staff = User::factory()->create();
        $this->actingAs($staff, 'web');
        $this->get('/admin/tickets?status=overdue')->assertOk()->assertSee('Server down')->assertSee('Overdue');

        app(TicketDesk::class)->staffReply($ticket, $staff, 'Looking now');
        $ticket->refresh();
        $this->assertNull($ticket->response_due_at);
        $this->assertEquals(now(), $ticket->first_response_at);

        // A client reply starts the clock again.
        $this->travel(10)->minutes();
        app(TicketDesk::class)->clientReply($ticket, 'Still down');
        $this->assertEquals(now()->addHour(), $ticket->fresh()->response_due_at);
    }

    public function test_changing_the_sla_updates_waiting_tickets(): void
    {
        $ticket = $this->open(TicketPriority::High);
        $this->assertNull($ticket->response_due_at);

        $this->actingAs(User::factory()->create(), 'web');
        $this->put("/admin/departments/{$this->technical->id}", ['name' => 'Technical', 'active' => '1', 'sla_hours' => ['high' => '2', 'low' => '']])->assertRedirect();

        $this->assertSame(['high' => 2], array_map('intval', $this->technical->fresh()->sla_hours));
        $this->assertEquals(now()->addHours(2), $ticket->fresh()->response_due_at);
    }

    public function test_staff_manage_rules(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->post(self::RULES, ['name' => 'Does nothing', 'minutes_since_reply' => '30'])->assertSessionHasErrors('name');
        $this->post(self::RULES, ['name' => 'Matches everything', 'set_priority' => 'high'])->assertSessionHasErrors('minutes_since_reply');
        $this->post(self::RULES, [
            'name' => 'Urgent and late', 'active' => '1', 'priorities' => ['urgent'], 'department_ids' => [$this->technical->id],
            'sla_breached' => '1', 'set_department_id' => $this->managers->id,
        ])->assertRedirect(self::RULES);

        $rule = EscalationRule::sole();
        $this->assertSame([[$this->technical->id], ['urgent'], true], [$rule->department_ids, $rule->priorities, $rule->sla_breached]);
        $this->get(self::RULES)->assertOk()->assertSee('Urgent and late')->assertSee('priority Urgent')->assertSee('Move to Managers');

        $this->actingAs(User::factory()->create(['role' => 'support']), 'web');
        $this->get(self::RULES)->assertForbidden();
    }

    public function test_rules_change_matching_tickets_once_until_the_next_reply(): void
    {
        $manager = User::factory()->create(['name' => 'Morgan']);
        $rule = EscalationRule::create([
            'name' => 'Urgent and late', 'priorities' => ['urgent'], 'statuses' => ['open', 'customer_reply'],
            'sla_breached' => true, 'set_department_id' => $this->managers->id, 'assign_to' => $manager->id,
            'set_priority' => 'high', 'notify_user_ids' => [$manager->id], 'reply' => 'A senior engineer is on it.',
        ]);
        $ticket = $this->open();
        $untouched = $this->open(TicketPriority::Medium);

        $this->artisan('tickets:escalate')->expectsOutputToContain('0 time(s)')->assertSuccessful();

        $this->travel(61)->minutes();
        $this->artisan('tickets:escalate')->expectsOutputToContain('1 time(s)')->assertSuccessful();
        $ticket->refresh();
        $this->assertSame([$this->managers->id, $manager->id, TicketPriority::High, [$rule->id]], [$ticket->department_id, $ticket->assigned_to, $ticket->priority, $ticket->escalations]);
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame('A senior engineer is on it.', $ticket->messages->last()->body);
        $this->assertNull($untouched->fresh()->escalations);
        Notification::assertSentTo($manager, StaffAlert::class);

        // Once per ticket, until someone replies.
        $ticket->update(['priority' => TicketPriority::Urgent, 'department_id' => $this->technical->id]);
        $this->travel(2)->hours();
        $this->artisan('tickets:escalate')->expectsOutputToContain('0 time(s)');
        app(TicketDesk::class)->clientReply($ticket, 'Any news?');
        $this->assertNull($ticket->fresh()->escalations);
        $this->travel(2)->hours();
        $this->artisan('tickets:escalate')->expectsOutputToContain('1 time(s)');
    }

    public function test_inactive_rules_and_closed_tickets_are_left_alone(): void
    {
        EscalationRule::create(['name' => 'Off', 'active' => false, 'minutes_since_reply' => 1, 'set_priority' => 'low']);
        EscalationRule::create(['name' => 'Stale', 'minutes_since_reply' => 60, 'set_status' => 'on_hold']);
        $ticket = $this->open();
        $closed = $this->open();
        $closed->update(['status' => TicketStatus::Closed]);

        $this->travel(2)->hours();
        $this->artisan('tickets:escalate')->expectsOutputToContain('1 time(s)');

        $this->assertSame([TicketStatus::OnHold, TicketPriority::Urgent], [$ticket->fresh()->status, $ticket->fresh()->priority]);
        $this->assertSame(TicketStatus::Closed, $closed->fresh()->status);
        // On hold isn't waiting on staff, so nothing is due.
        $this->assertNull($ticket->fresh()->response_due_at);
    }
}
