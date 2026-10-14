<?php

namespace Tests\Feature\Support;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\EmailLog;
use App\Models\User;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InactiveTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->travelTo('2026-10-14 09:00');
    }

    public function test_answered_tickets_close_after_the_set_hours_without_a_reply(): void
    {
        $desk = app(TicketDesk::class);
        $department = Department::create(['name' => 'Technical', 'active' => true, 'feedback_requests' => true]);
        $client = Client::factory()->create();
        $staff = User::factory()->create();

        $answered = $desk->open($client, $department, 'Answered', 'Help');
        $desk->staffReply($answered, $staff, 'Try now');
        $waiting = $desk->open($client, $department, 'Waiting on us', 'Help');

        $this->artisan('tickets:close-inactive')->expectsOutputToContain('off')->assertSuccessful();

        $this->actingAs($staff, 'web')->put('/admin/settings', ['late_fee_type' => 'fixed', 'close_inactive_hours' => 48])->assertSessionHasNoErrors();
        $this->travel(47)->hours();
        $this->artisan('tickets:close-inactive')->expectsOutputToContain('Closed 0 inactive')->assertSuccessful();

        $this->travel(2)->hours();
        $this->artisan('tickets:close-inactive')->expectsOutputToContain('Closed 1 inactive')->assertSuccessful();

        $this->assertSame(TicketStatus::Closed, $answered->fresh()->status);
        $this->assertSame(TicketStatus::Open, $waiting->fresh()->status);
        $closed = EmailLog::where('template', 'ticket_auto_closed')->sole();
        $this->assertStringContainsString('in 48 hours', $closed->body);
        $this->assertSame(1, EmailLog::where('template', 'ticket_feedback')->count());

        // A reply opens it again.
        $desk->clientReply($answered->fresh(), 'Still broken');
        $this->assertSame(TicketStatus::CustomerReply, $answered->fresh()->status);
        $this->assertNull($answered->fresh()->closed_at);
    }
}
