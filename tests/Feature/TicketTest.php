<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\TicketStatus;
use App\Mail\LoggedEmail;
use App\Models\Client;
use App\Models\Department;
use App\Models\EmailLog;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $this->department = Department::create(['name' => 'Technical', 'email' => 'support@example.com', 'active' => true]);
    }

    private function openTicket(Client $client): Ticket
    {
        $this->actingAs($client, 'client');
        $this->post('/portal/support', [
            'department_id' => $this->department->id,
            'subject' => 'Site is down',
            'priority' => 'high',
            'body' => 'My site returns a 500 error.',
            'attachments' => [UploadedFile::fake()->createWithContent('error.txt', 'stack trace')],
        ])->assertRedirect();

        return Ticket::sole();
    }

    public function test_client_opens_a_ticket_with_an_attachment(): void
    {
        $support = User::factory()->create(['role' => StaffRole::Support]);
        $nobody = User::factory()->create(['role' => StaffRole::Custom, 'permissions' => ['manage-billing']]);
        $client = Client::factory()->create();

        $ticket = $this->openTicket($client);

        $this->assertSame([TicketStatus::Open, 'high', $client->id], [$ticket->status, $ticket->priority->value, $ticket->client_id]);
        $file = TicketAttachment::sole();
        $this->assertSame(['error.txt', 11], [$file->name, $file->size]);
        Storage::disk('local')->assertExists($file->path);

        $log = EmailLog::sole();
        $this->assertSame(['ticket_opened', "[Ticket #{$ticket->id}] Site is down", 'support@example.com'], [$log->template, $log->subject, $log->reply_to]);
        Mail::assertSent(LoggedEmail::class, fn (LoggedEmail $mail) => $mail->hasReplyTo('support@example.com'));

        Notification::assertSentTo($support, StaffAlert::class);
        Notification::assertNotSentTo($nobody, StaffAlert::class);

        $this->get('/portal/support')->assertOk()->assertSee('Site is down');
        $this->get("/portal/support/{$ticket->id}")->assertOk()->assertSee('My site returns a 500 error.');
        $this->get("/portal/support/{$ticket->id}/files/{$file->id}")->assertOk()->assertDownload('error.txt');
    }

    public function test_clients_only_see_their_own_tickets(): void
    {
        $ticket = $this->openTicket(Client::factory()->create());
        $file = TicketAttachment::sole();

        $this->actingAs(Client::factory()->create(), 'client');
        $this->get("/portal/support/{$ticket->id}")->assertNotFound();
        $this->get("/portal/support/{$ticket->id}/files/{$file->id}")->assertNotFound();
        $this->post("/portal/support/{$ticket->id}/reply", ['body' => 'Hi'])->assertNotFound();
        $this->post("/portal/support/{$ticket->id}/close")->assertNotFound();
    }

    public function test_attachments_must_be_allowed_types(): void
    {
        $this->actingAs(Client::factory()->create(), 'client');
        $this->post('/portal/support', [
            'department_id' => $this->department->id, 'subject' => 'X', 'priority' => 'low', 'body' => 'Y',
            'attachments' => [UploadedFile::fake()->createWithContent('run.php', '<?php echo 1;')],
        ])->assertSessionHasErrors('attachments.0');

        $off = Department::create(['name' => 'Closed', 'active' => false]);
        $this->post('/portal/support', ['department_id' => $off->id, 'subject' => 'X', 'priority' => 'low', 'body' => 'Y'])
            ->assertSessionHasErrors('department_id');
        $this->assertSame(0, Ticket::count());
    }

    public function test_staff_reply_emails_the_client_but_internal_notes_stay_private(): void
    {
        $client = Client::factory()->create();
        $ticket = $this->openTicket($client);
        $staff = User::factory()->create(['name' => 'Riley', 'role' => StaffRole::Support]);
        $this->actingAs($staff, 'web');

        $this->get('/admin/tickets')->assertOk()->assertSee('Site is down');
        $this->post("/admin/tickets/{$ticket->id}/reply", ['body' => 'Only staff should see this', 'internal' => '1'])->assertRedirect();
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
        $this->assertSame(1, EmailLog::count());

        $this->post("/admin/tickets/{$ticket->id}/reply", ['body' => 'Fixed, please check.'])->assertRedirect();
        $this->assertSame(TicketStatus::Answered, $ticket->fresh()->status);
        $reply = EmailLog::where('template', 'ticket_reply')->sole();
        $this->assertStringContainsString('Riley replied', $reply->body);
        $this->assertStringContainsString('Fixed, please check.', $reply->body);
        $this->assertStringStartsWith('-- Reply above this line --', $reply->body);

        $this->actingAs($client, 'client');
        $this->get("/portal/support/{$ticket->id}")->assertSee('Fixed, please check.')->assertDontSee('Only staff should see this');
    }

    public function test_staff_assign_and_the_assignee_gets_client_replies(): void
    {
        $client = Client::factory()->create();
        $ticket = $this->openTicket($client);
        $alex = User::factory()->create(['role' => StaffRole::Support]);
        $sam = User::factory()->create(['role' => StaffRole::Support]);

        $this->actingAs($alex, 'web');
        $this->put("/admin/tickets/{$ticket->id}", [
            'status' => 'on_hold', 'priority' => 'urgent', 'department_id' => $this->department->id, 'assigned_to' => $sam->id,
        ])->assertRedirect();
        $this->assertSame([TicketStatus::OnHold, 'urgent', $sam->id], [$ticket->fresh()->status, $ticket->fresh()->priority->value, $ticket->fresh()->assigned_to]);
        $this->get('/admin/tickets?mine=1&status=all')->assertDontSee('Site is down');

        Notification::fake();
        $this->actingAs($client, 'client');
        $this->post("/portal/support/{$ticket->id}/close")->assertRedirect();
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->post("/portal/support/{$ticket->id}/reply", ['body' => 'Still broken.'])->assertRedirect();

        $this->assertSame(TicketStatus::CustomerReply, $ticket->fresh()->status);
        Notification::assertSentTo($sam, StaffAlert::class);
        Notification::assertNotSentTo($alex, StaffAlert::class);
    }

    public function test_only_staff_who_handle_tickets_can_open_them(): void
    {
        $ticket = $this->openTicket(Client::factory()->create());
        $file = TicketAttachment::sole();

        $this->actingAs(User::factory()->create(['role' => StaffRole::Custom, 'permissions' => ['manage-clients']]), 'web');
        $this->get('/admin/tickets')->assertForbidden();
        $this->get("/admin/tickets/{$ticket->id}")->assertForbidden();
        $this->get("/admin/tickets/{$ticket->id}/files/{$file->id}")->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');
        $this->get("/admin/tickets/{$ticket->id}/files/{$file->id}")->assertOk()->assertDownload('error.txt');
        $other = Ticket::create(['client_id' => $ticket->client_id, 'department_id' => $this->department->id, 'subject' => 'Other']);
        $this->get("/admin/tickets/{$other->id}/files/{$file->id}")->assertNotFound();
    }

    public function test_admins_manage_departments(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/admin/departments', ['name' => 'Sales', 'email' => 'sales@example.com', 'active' => '1'])->assertRedirect('/admin/departments');
        $this->post('/admin/departments', ['name' => 'Dupe', 'email' => 'sales@example.com'])->assertSessionHasErrors('email');
        $sales = Department::where('name', 'Sales')->sole();
        $this->put("/admin/departments/{$sales->id}", ['name' => 'Sales', 'email' => 'sales@example.com'])->assertRedirect();
        $this->assertFalse($sales->fresh()->active);
        $this->get('/admin/departments')->assertOk()->assertSee('sales@example.com');

        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]));
        $this->get('/admin/departments')->assertForbidden();
    }
}
