<?php

namespace Tests\Feature\Support;

use App\Enums\TicketPriority;
use App\Models\Client;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Tickets\BusinessHours;
use App\Tickets\TicketDesk;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BusinessHoursTest extends TestCase
{
    use RefreshDatabase;

    private const WEEKDAYS = ['mon' => ['09:00', '17:00'], 'tue' => ['09:00', '17:00'], 'wed' => ['09:00', '17:00'], 'thu' => ['09:00', '17:00'], 'fri' => ['09:00', '17:00']];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        config(['tickets.business_hours' => self::WEEKDAYS, 'tickets.holidays' => ['2026-10-19'], 'tickets.timezone' => 'America/New_York']);
    }

    private function hours(): BusinessHours
    {
        return BusinessHours::fromConfig();
    }

    public function test_hours_count_only_while_open(): void
    {
        $at = fn (string $time) => CarbonImmutable::parse($time, 'America/New_York');

        // Friday 4pm plus two hours: one hour on Friday, Monday is a holiday, one hour on Tuesday.
        $this->assertEquals($at('2026-10-20 10:00'), $this->hours()->addHours($at('2026-10-16 16:00'), 2));
        // Before opening, the clock starts at opening time.
        $this->assertEquals($at('2026-10-14 09:30'), $this->hours()->addHours($at('2026-10-14 06:00'), 0.5));
        // Times in another timezone are counted in the business's timezone and come back in theirs.
        $due = $this->hours()->addHours(CarbonImmutable::parse('2026-10-14 12:00', 'UTC'), 1);
        $this->assertSame(['2026-10-14 10:00', 'UTC'], [$due->setTimezone('America/New_York')->format('Y-m-d H:i'), $due->getTimezone()->getName()]);
        $this->assertTrue($this->hours()->isOpen($at('2026-10-14 16:59')));
        $this->assertFalse($this->hours()->isOpen($at('2026-10-17 12:00')));
    }

    public function test_no_opening_hours_count_every_hour(): void
    {
        config(['tickets.business_hours' => []]);

        $from = CarbonImmutable::parse('2026-10-17 12:00');
        $this->assertEquals($from->addHours(3), $this->hours()->addHours($from, 3));
    }

    public function test_departments_can_count_business_hours_for_their_targets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 16:30', 'America/New_York'));
        $department = Department::create(['name' => 'Sales', 'active' => true, 'sla_hours' => ['medium' => 1], 'sla_business_hours' => true]);
        $calendar = Department::create(['name' => 'Emergency', 'active' => true, 'sla_hours' => ['medium' => 1]]);
        $client = Client::factory()->create();

        $ticket = app(TicketDesk::class)->open($client, $department, 'Quote', 'How much?', TicketPriority::Medium);
        $this->assertSame('2026-10-20 09:30', $ticket->response_due_at->setTimezone('America/New_York')->format('Y-m-d H:i'));
        $other = app(TicketDesk::class)->open($client, $calendar, 'Down', 'Help', TicketPriority::Medium);
        $this->assertEquals(now()->addHour(), $other->response_due_at);

        // Changing the hours moves waiting tickets' due times.
        $this->actingAs(User::factory()->create(), 'web');
        $this->put('/admin/settings', [
            'late_fee_type' => 'fixed',
            'hours' => ['fri' => ['open' => '1', 'from' => '09:00', 'to' => '18:00'], 'mon' => ['open' => '0'], 'tue' => ['open' => '1', 'from' => '08:00', 'to' => '17:00']],
            'holidays' => '',
            'timezone' => 'America/New_York',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['tue' => ['08:00', '17:00'], 'fri' => ['09:00', '18:00']], config('tickets.business_hours'));
        $this->assertSame('2026-10-16 17:30', Ticket::find($ticket->id)->response_due_at->setTimezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertEquals(now()->addHour(), $other->fresh()->response_due_at);
    }

    public function test_settings_check_the_hours_and_dates(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->get('/admin/settings')->assertOk()->assertSee('Business hours')->assertSee('2026-10-19');
        $this->put('/admin/settings', [
            'late_fee_type' => 'fixed',
            'hours' => ['mon' => ['open' => '1', 'from' => '17:00', 'to' => '09:00']],
            'holidays' => "2026-12-25\nChristmas",
            'timezone' => 'America/New_York',
        ])->assertSessionHasErrors(['hours.mon.to' => 'Monday has to close after it opens.', 'holidays']);
        $this->put('/admin/settings', ['late_fee_type' => 'fixed', 'timezone' => 'Mars/Olympus'])->assertSessionHasErrors('timezone');

        $this->put('/admin/settings', ['late_fee_type' => 'fixed', 'hours' => ['sat' => ['open' => '1', 'from' => '10:00', 'to' => '14:00']], 'holidays' => '2026-12-26, 2026-12-25'])->assertSessionHasNoErrors();
        $this->assertSame(['sat' => ['10:00', '14:00']], config('tickets.business_hours'));
        $this->assertSame(['2026-12-25', '2026-12-26'], config('tickets.holidays'));
    }
}
