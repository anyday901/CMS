<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Tickets\InboundMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundTicketMailTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $this->department = Department::create(['name' => 'Technical', 'email' => 'support@example.com', 'active' => true]);
        $this->client = Client::factory()->create(['email' => 'jane@example.com']);
    }

    private function email(string $subject, string $body, string $from = 'Jane Doe <Jane@Example.com>', string $to = 'support@example.com', string $extra = ''): string
    {
        return "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nMessage-ID: <x@example.com>\r\nMIME-Version: 1.0\r\n{$extra}Content-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n";
    }

    public function test_an_email_to_a_department_opens_a_ticket_and_a_reply_adds_to_it(): void
    {
        [$outcome] = app(InboundMail::class)->handle($this->email('Cannot log in', "Help please.\n\nJane"));
        $this->assertSame(InboundMail::OPENED, $outcome);

        $ticket = Ticket::sole();
        $this->assertSame(['Cannot log in', $this->client->id, $this->department->id], [$ticket->subject, $ticket->client_id, $ticket->department_id]);
        $this->assertSame(['Help please.'."\n\nJane", 'email'], [TicketMessage::sole()->body, TicketMessage::sole()->via]);

        $ticket->update(['status' => TicketStatus::Answered]);
        $reply = "Thanks, that worked.\n\nOn Mon, Oct 12, 2026 at 9:00 AM Support <support@example.com> wrote:\n> -- Reply above this line --\n> Hello";
        [$outcome] = app(InboundMail::class)->handle($this->email("Re: [Ticket #{$ticket->id}] Cannot log in", $reply));

        $this->assertSame(InboundMail::REPLIED, $outcome);
        $this->assertSame(TicketStatus::CustomerReply, $ticket->fresh()->status);
        $this->assertSame('Thanks, that worked.', TicketMessage::latest('id')->first()->body);
    }

    public function test_reply_marker_cuts_the_quoted_email(): void
    {
        $ticket = Ticket::create(['client_id' => $this->client->id, 'department_id' => $this->department->id, 'subject' => 'Billing']);

        app(InboundMail::class)->handle($this->email("[Ticket #{$ticket->id}] Billing", "Got it\n\n-- Reply above this line --\nold text"));

        $this->assertSame('Got it', TicketMessage::sole()->body);
    }

    public function test_strangers_auto_replies_and_other_clients_tickets_are_ignored(): void
    {
        $inbound = app(InboundMail::class);
        $this->assertSame(InboundMail::IGNORED, $inbound->handle($this->email('Spam', 'Buy now', 'spam@example.net'))[0]);
        $this->assertSame(InboundMail::IGNORED, $inbound->handle($this->email('Out of office', 'Away', extra: "Auto-Submitted: auto-replied\r\n"))[0]);
        $this->assertSame(InboundMail::IGNORED, $inbound->handle($this->email('Hello', 'Hi', to: 'random@example.com'))[0]);

        // A ticket number belonging to someone else opens a new ticket instead of replying.
        $other = Ticket::create(['client_id' => Client::factory()->create()->id, 'department_id' => $this->department->id, 'subject' => 'Theirs']);
        $this->assertSame(InboundMail::OPENED, $inbound->handle($this->email("[Ticket #{$other->id}] Theirs", 'Sneaky'))[0]);
        $this->assertSame(0, $other->messages()->count());
        $this->assertSame(2, Ticket::count());
    }

    public function test_attachments_are_saved_and_unsafe_ones_skipped(): void
    {
        $boundary = 'b1';
        $raw = "From: jane@example.com\r\nTo: support@example.com\r\nSubject: Screenshot\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n"
            ."--{$boundary}\r\nContent-Type: text/plain\r\n\r\nSee attached.\r\n"
            ."--{$boundary}\r\nContent-Type: image/png; name=\"shot.png\"\r\nContent-Disposition: attachment; filename=\"shot.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('PNGDATA')."\r\n"
            ."--{$boundary}\r\nContent-Type: application/octet-stream; name=\"evil.exe\"\r\nContent-Disposition: attachment; filename=\"evil.exe\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('MZ')."\r\n"
            ."--{$boundary}--\r\n";

        app(InboundMail::class)->handle($raw);

        $file = TicketAttachment::sole();
        $this->assertSame(['shot.png', 7], [$file->name, $file->size]);
        $this->assertSame('PNGDATA', Storage::disk('local')->get($file->path));
        $this->assertSame('See attached.', TicketMessage::sole()->body);
    }

    public function test_pipe_command_can_force_a_department(): void
    {
        $sales = Department::create(['name' => 'Sales', 'active' => true]);
        $path = tempnam(sys_get_temp_dir(), 'mail');
        file_put_contents($path, $this->email('Pricing', 'How much?', to: 'catchall@example.com'));

        $this->artisan('tickets:pipe', ['file' => $path, '--department' => 999])->assertFailed();
        $this->artisan('tickets:pipe', ['file' => $path])->expectsOutputToContain('Ignored')->assertSuccessful();
        $this->assertSame(0, Ticket::count());

        $this->artisan('tickets:pipe', ['file' => $path, '--department' => $sales->id])->expectsOutputToContain('Opened')->assertSuccessful();
        $this->assertSame($sales->id, Ticket::sole()->department_id);
        unlink($path);
    }

    public function test_http_endpoint_needs_the_secret(): void
    {
        $raw = $this->email('Via HTTP', 'Hello');

        $this->call('POST', '/webhooks/email', [], [], [], ['CONTENT_TYPE' => 'message/rfc822'], $raw)->assertNotFound();

        config(['tickets.inbound_secret' => 'pipe-secret']);
        $this->call('POST', '/webhooks/email', [], [], [], ['CONTENT_TYPE' => 'message/rfc822', 'HTTP_X_INBOUND_SECRET' => 'wrong'], $raw)->assertForbidden();
        $this->call('POST', '/webhooks/email', [], [], [], ['CONTENT_TYPE' => 'message/rfc822', 'HTTP_X_INBOUND_SECRET' => 'pipe-secret'], $raw)
            ->assertOk()->assertSee('opened');
        $this->call('POST', '/webhooks/email?key=pipe-secret', [], [], [], ['CONTENT_TYPE' => 'message/rfc822'], $this->email('Second', 'Again'))->assertOk();

        $this->assertSame(['Via HTTP', 'Second'], Ticket::orderBy('id')->pluck('subject')->all());
    }
}
