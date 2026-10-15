<?php

namespace Tests\Feature\Support;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\EmailLog;
use App\Models\Ticket;
use App\Models\TicketFeedback;
use App\Models\User;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const FEEDBACK_PROMPT = 'How did we do?';

    private const COMMENT = 'Quick fix, thanks';

    private Department $department;

    private Client $client;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->department = Department::create(['name' => 'Technical', 'active' => true, 'feedback_requests' => true]);
        $this->client = Client::factory()->create();
        $this->staff = User::factory()->create(['name' => 'Jane Staff']);
    }

    private function answered(): Ticket
    {
        $ticket = app(TicketDesk::class)->open($this->client, $this->department, 'Site down', 'Help');
        app(TicketDesk::class)->staffReply($ticket, $this->staff, 'Fixed');

        return $ticket->fresh();
    }

    private function close(Ticket $ticket): void
    {
        $this->actingAs($this->staff, 'web')->put(route('admin.tickets.update', $ticket), [
            'status' => 'closed', 'priority' => $ticket->priority->value, 'department_id' => $ticket->department_id,
        ])->assertRedirect();
    }

    public function test_staff_closing_a_ticket_asks_the_client_to_rate_it(): void
    {
        $ticket = $this->answered();
        $this->close($ticket);

        $this->assertNotNull($ticket->fresh()->closed_at);
        $log = EmailLog::where('template', 'ticket_feedback')->sole();
        $this->assertSame("How did we do with ticket #{$ticket->id}?", $log->subject);
        $this->assertStringContainsString(route('portal.tickets.show', $ticket).'#feedback', $log->body);

        $this->actingAs($this->client, 'client')->get(route('portal.tickets.show', $ticket))->assertOk()->assertSee(self::FEEDBACK_PROMPT);
        $this->post(route('portal.tickets.feedback', $ticket), ['rating' => 4, 'comment' => self::COMMENT])->assertRedirect(route('portal.tickets.show', $ticket));

        $feedback = TicketFeedback::sole();
        $this->assertSame([4, self::COMMENT, $this->client->id], [$feedback->rating, $feedback->comment, $feedback->client_id]);
        $this->get(route('portal.tickets.show', $ticket))->assertSee('Your feedback')->assertDontSee(self::FEEDBACK_PROMPT);

        // Once per ticket.
        $this->post(route('portal.tickets.feedback', $ticket), ['rating' => 1])->assertForbidden();
        $this->assertSame(1, TicketFeedback::count());

        $this->actingAs($this->staff, 'web')->get(route('admin.tickets.show', $ticket))->assertSee('Client feedback')->assertSee(self::COMMENT);
    }

    public function test_only_closed_tickets_in_departments_that_ask_can_be_rated(): void
    {
        $ticket = $this->answered();
        $this->actingAs($this->client, 'client')->post(route('portal.tickets.feedback', $ticket), ['rating' => 5])->assertForbidden();

        $this->department->update(['feedback_requests' => false]);
        $this->close($ticket);
        $this->assertSame(0, EmailLog::where('template', 'ticket_feedback')->count());
        $this->actingAs($this->client, 'client')->get(route('portal.tickets.show', $ticket))->assertDontSee(self::FEEDBACK_PROMPT);
        $this->post(route('portal.tickets.feedback', $ticket), ['rating' => 5])->assertForbidden();
    }

    public function test_a_client_closing_their_ticket_sees_the_form_without_an_email(): void
    {
        $ticket = $this->answered();

        $this->actingAs($this->client, 'client')->post(route('portal.tickets.close', $ticket))->assertRedirect();
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertSame(0, EmailLog::where('template', 'ticket_feedback')->count());
        $this->get(route('portal.tickets.show', $ticket))->assertSee(self::FEEDBACK_PROMPT);
        $this->post(route('portal.tickets.feedback', $ticket), ['rating' => 6])->assertSessionHasErrors('rating');
    }

    public function test_the_feedback_report_averages_ratings_overall_and_by_staff(): void
    {
        foreach ([5, 3] as $rating) {
            $ticket = $this->answered();
            $this->close($ticket);
            TicketFeedback::create(['ticket_id' => $ticket->id, 'client_id' => $this->client->id, 'rating' => $rating, 'comment' => "Rated {$rating}"]);
        }

        $this->actingAs($this->staff, 'web')->get(route('admin.tickets.feedback'))
            ->assertOk()
            ->assertSee('4.00')
            ->assertSee('from 2 ratings')
            ->assertSee('Jane Staff')
            ->assertSee('Rated 3');

        $other = Department::create(['name' => 'Sales', 'active' => true]);
        $this->get(route('admin.tickets.feedback', ['department' => $other->id]))->assertSee('No ratings in this period yet.');
    }
}
