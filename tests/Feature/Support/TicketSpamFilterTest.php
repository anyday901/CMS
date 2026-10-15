<?php

namespace Tests\Feature\Support;

use App\Models\Client;
use App\Models\Department;
use App\Models\InboundEmail;
use App\Models\Ticket;
use App\Models\User;
use App\Tickets\InboundMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketSpamFilterTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = 'Jane <jane@example.com>';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        Department::create(['name' => 'Support', 'email' => 'support@example.com', 'active' => true]);
        Client::factory()->create(['email' => 'jane@example.com']);
    }

    private function handle(string $subject, string $body = 'Hello', string $from = self::FROM, string $headers = ''): array
    {
        $raw = "From: {$from}\r\nTo: support@example.com\r\nSubject: {$subject}\r\nMessage-ID: <".uniqid('', true)."@example.com>\r\n{$headers}Content-Type: text/plain\r\n\r\n{$body}\r\n";

        return app(InboundMail::class)->handle($raw);
    }

    public function test_email_the_mail_server_marked_as_spam_is_dropped_unless_that_check_is_off(): void
    {
        [$outcome, $reason] = $this->handle('Win now', headers: "X-Spam-Flag: YES\r\n");
        $this->assertSame(InboundMail::SPAM, $outcome);
        $this->assertStringContainsString('mail server marked it as spam', $reason);
        $this->assertSame(InboundMail::SPAM, $this->handle('Win now', headers: "X-Spam-Status: Yes, score=9.1\r\n")[0]);

        config(['tickets.spam.use_spam_headers' => false]);
        $this->assertSame(InboundMail::OPENED, $this->handle('Win now', headers: "X-Spam-Flag: YES\r\n")[0]);
        $this->assertSame(1, Ticket::count());
    }

    public function test_blocked_senders_domains_and_phrases_are_dropped(): void
    {
        config(['tickets.guest_tickets' => true, 'tickets.spam' => [
            'use_spam_headers' => true,
            'blocked_senders' => ['@spam.example', 'pest@example.org'],
            'blocked_subjects' => ['Casino Bonus'],
            'blocked_phrases' => ['cheap backlinks'],
            'new_tickets_per_hour' => null,
        ]]);

        $this->assertStringContainsString('@spam.example', $this->handle('Hi', from: 'x@mail.spam.example')[1]);
        $this->assertSame(InboundMail::SPAM, $this->handle('Hi', from: 'pest@example.org')[0]);
        $this->assertSame(InboundMail::SPAM, $this->handle('Your casino bonus')[0]);
        $this->assertSame(InboundMail::SPAM, $this->handle('Hi', 'We sell CHEAP backlinks')[0]);
        // A domain rule doesn't catch a domain that merely ends the same way.
        $this->assertSame(InboundMail::OPENED, $this->handle('Hi', from: 'ok@notspam.example')[0]);

        $this->assertSame(1, Ticket::count());
        $this->assertSame(4, InboundEmail::where('outcome', InboundEmail::SPAM)->count());
    }

    public function test_one_sender_can_only_open_so_many_tickets_an_hour_but_can_still_reply(): void
    {
        config(['tickets.spam.new_tickets_per_hour' => 2]);

        $this->handle('One');
        $this->handle('Two');
        [$outcome, $reason] = $this->handle('Three');

        $this->assertSame(InboundMail::SPAM, $outcome);
        $this->assertStringContainsString('already opened 2 tickets in the last hour', $reason);
        $this->assertSame(InboundMail::REPLIED, $this->handle('Re: [Ticket #'.Ticket::first()->id.'] One')[0]);

        $this->travel(61)->minutes();
        $this->assertSame(InboundMail::OPENED, $this->handle('Four')[0]);
    }

    public function test_the_contact_form_drops_spam_quietly_and_logs_it(): void
    {
        config(['tickets.guest_tickets' => true, 'tickets.spam.blocked_phrases' => ['crypto']]);
        $department = Department::first();
        $form = ['name' => 'Bot', 'email' => 'bot@example.net', 'department_id' => $department->id, 'priority' => 'low', 'subject' => 'Hi'];

        $this->post(route('tickets.guest.store'), $form + ['body' => 'Buy crypto'])->assertOk()->assertSee('got your message');
        $this->post(route('tickets.guest.store'), $form + ['body' => 'Hello', 'website' => 'http://bot.example'])->assertOk();

        $this->assertSame(0, Ticket::count());
        $this->assertSame(['contact form: the hidden field bots fill in was filled in', 'contact form: the message contains the blocked phrase "crypto"'],
            InboundEmail::orderByDesc('id')->pluck('reason')->all());
    }

    public function test_staff_set_the_rules_under_settings_and_see_what_was_caught(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin, 'web')->put(route('admin.settings.update'), [
            'late_fee_type' => 'fixed',
            'spam_use_headers' => '1',
            'spam_blocked_senders' => "Spam@Example.com\n\n@junk.example\nspam@example.com",
            'spam_blocked_subjects' => 'Casino',
            'spam_blocked_phrases' => '',
            'spam_new_tickets_per_hour' => '5',
            'guest_tickets' => '1',
        ])->assertRedirect(route('admin.settings.edit'));

        $this->assertSame(['spam@example.com', '@junk.example'], config('tickets.spam.blocked_senders'));
        $this->assertSame(['Casino'], config('tickets.spam.blocked_subjects'));
        $this->assertSame([], config('tickets.spam.blocked_phrases'));
        $this->assertSame(5, config('tickets.spam.new_tickets_per_hour'));
        $this->assertTrue(config('tickets.guest_tickets'));

        $this->handle('Casino night');
        $this->get(route('admin.tickets.inbound', ['outcome' => 'spam']))->assertOk()->assertSee('Casino night')->assertSee('blocked phrase');
        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('@junk.example');
    }

    public function test_the_log_is_pruned_after_ninety_days(): void
    {
        InboundEmail::create(['from' => 'old@example.com', 'outcome' => 'ignored']);
        $this->travel(InboundEmail::KEEP_DAYS + 1)->days();
        InboundEmail::create(['from' => 'new@example.com', 'outcome' => 'ignored']);

        $this->artisan('model:prune', ['--model' => [InboundEmail::class]])->assertSuccessful();

        $this->assertSame(['new@example.com'], InboundEmail::pluck('from')->all());
    }
}
