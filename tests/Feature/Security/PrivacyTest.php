<?php

namespace Tests\Feature\Security;

use App\Enums\ServiceStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Department;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\LoginAttempt;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketFeedback;
use App\Models\TicketMessage;
use App\Models\User;
use App\Privacy\ClientAnonymiser;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const PAT = 'pat@example.com';

    private const ANONYMISE_BUTTON = 'Anonymise this client';

    private const JANE = 'jane@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
    }

    /** A client with something in every part of their account. */
    private function busyClient(): Client
    {
        $client = Client::factory()->create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => self::JANE, 'password' => 'jane-password-1',
            'phone' => '555-0100', 'address1' => '1 Main St', 'city' => 'Austin', 'notes' => 'VIP',
        ]);
        $client->contacts()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::PAT, 'invoice_emails' => true]);
        Service::factory()->for($client)->create(['label' => 'jane.example', 'status' => ServiceStatus::Cancelled, 'custom_fields' => ['note' => 'x']]);
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 1500]);
        $invoice->recalculate();

        $department = Department::create(['name' => 'Support']);
        $desk = app(TicketDesk::class);
        $ticket = $desk->open($client, $department, 'My card number is 4111', 'Please call me on 555-0100', files: [['name' => 'id.png', 'mime' => 'image/png', 'contents' => 'png']]);
        $desk->staffReply($ticket, User::factory()->create(), 'Internal: she sounds upset', internal: true);
        TicketFeedback::create(['ticket_id' => $ticket->id, 'client_id' => $client->id, 'rating' => 5, 'comment' => 'Jane loved it']);

        LoginAttempt::create(['area' => 'portal', 'email' => self::JANE, 'client_id' => $client->id, 'ip_address' => '198.51.100.7', 'outcome' => 'success', 'created_at' => now()]);
        Activity::record('Logged in to the portal as Jane Doe', $client);
        Activity::record('Verified their email address '.self::JANE, $client);
        Activity::create(['actor_type' => 'client', 'actor_id' => $client->id, 'description' => 'Updated their phone number', 'actor_name' => 'Pat Lee (contact)', 'client_id' => $client->id]);
        DB::table('client_password_reset_tokens')->insert(['email' => self::JANE, 'token' => 'x', 'created_at' => now()]);
        DB::table('contact_password_reset_tokens')->insert(['email' => self::PAT, 'token' => 'y', 'created_at' => now()]);

        return $client->fresh();
    }

    public function test_staff_and_the_client_download_everything_kept_about_the_account(): void
    {
        $client = $this->busyClient();
        $this->actingAs(User::factory()->create(), 'web');

        $response = $this->get("/admin/clients/{$client->id}/export")->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString('attachment; filename="client-'.$client->id.'-data-', $response->headers->get('Content-Disposition'));
        $data = json_decode($response->getContent(), true);

        $this->assertSame([self::JANE, '1 Main St'], [$data['client']['email'], $data['client']['address1']]);
        $this->assertSame(self::PAT, $data['contacts'][0]['email']);
        $this->assertSame('jane.example', $data['services'][0]['label']);
        $this->assertSame('Hosting', $data['invoices'][0]['items'][0]['description']);
        $this->assertSame(['id.png'], $data['tickets'][0]['messages'][0]['attachments']);
        $this->assertSame([5, 'Jane loved it'], [$data['tickets'][0]['feedback']['rating'], $data['tickets'][0]['feedback']['comment']]);
        $this->assertSame('198.51.100.7', $data['logins'][0]['ip_address']);
        $this->assertNotEmpty($data['emails']);

        // No secrets, and nothing from staff's internal notes.
        $json = $response->getContent();
        foreach (['password', 'two_factor_secret', 'remember_token', 'Internal: she sounds upset'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }

        $this->actingAs($client, 'client');
        $this->get('/portal/account/export')->assertOk()->assertJsonPath('client.email', self::JANE);
        $this->assertSame(1, Activity::where('description', 'Downloaded their personal data')->count());
    }

    public function test_only_closed_accounts_without_running_services_can_be_anonymised(): void
    {
        $client = $this->busyClient();
        $this->actingAs(User::factory()->create(), 'web');

        $this->get("/admin/clients/{$client->id}")->assertDontSee(self::ANONYMISE_BUTTON);
        $this->post("/admin/clients/{$client->id}/anonymise", ['confirm' => '1'])->assertSessionHasErrors('anonymise');

        $client->update(['status' => 'closed']);
        $running = Service::factory()->for($client)->create();
        $this->get("/admin/clients/{$client->id}")->assertSee(self::ANONYMISE_BUTTON);
        $this->post("/admin/clients/{$client->id}/anonymise")->assertSessionHasErrors('confirm');
        $this->post("/admin/clients/{$client->id}/anonymise", ['confirm' => '1'])->assertSessionHasErrors(['anonymise' => 'Terminate or cancel the client\'s services first.']);

        $running->update(['status' => ServiceStatus::Terminated]);
        $this->assertNull(app(ClientAnonymiser::class)->problem($client->fresh()));
    }

    public function test_anonymising_removes_personal_details_and_keeps_the_books(): void
    {
        $client = $this->busyClient();
        $client->update(['status' => 'closed']);
        $path = TicketAttachment::sole()->path;
        Storage::disk('local')->assertExists($path);
        $this->actingAs(User::factory()->create(), 'web');

        $this->post("/admin/clients/{$client->id}/anonymise", ['confirm' => '1'])->assertRedirect("/admin/clients/{$client->id}?tab=security")->assertSessionHas('status');

        $client->refresh();
        $this->assertTrue($client->isAnonymised());
        $this->assertSame("Anonymised client {$client->id}", $client->fullName());
        $this->assertSame("anonymised-{$client->id}@anonymised.invalid", $client->email);
        $this->assertNull($client->phone);
        $this->assertNull($client->address1);
        $this->assertNull($client->notes);
        $this->assertNull($client->password);
        $this->assertFalse($client->canLogIn());

        $this->assertSame(0, ClientContact::count());
        $this->assertSame(0, EmailLog::where('client_id', $client->id)->count());
        $this->assertSame(0, LoginAttempt::count());
        $this->assertSame(0, TicketAttachment::count());
        Storage::disk('local')->assertMissing($path);
        $this->assertSame([ClientAnonymiser::REMOVED], TicketMessage::pluck('body')->unique()->values()->all());
        $this->assertSame('Anonymised ticket', Ticket::sole()->subject);

        // The books are kept.
        $this->assertSame(1500, Invoice::sole()->total);
        $this->assertSame('jane.example', Service::sole()->label);
        $this->assertNull(Service::sole()->custom_fields);

        // Names, emails and ticket subjects are gone from the activity log.
        foreach (['Jane Doe', self::JANE, 'Pat Lee', 'card number'] as $personal) {
            $this->assertSame(0, Activity::where('description', 'like', "%{$personal}%")->orWhere('actor_name', 'like', "%{$personal}%")->count(), $personal);
        }
        $this->assertSame(0, DB::table('client_password_reset_tokens')->count() + DB::table('contact_password_reset_tokens')->count());
        $this->assertSame(0, Activity::where('client_id', $client->id)->whereNotNull('ip_address')->where('description', '!=', "Anonymised client #{$client->id}")->count());

        $this->get("/admin/clients/{$client->id}")->assertOk()->assertSee('anonymised')->assertDontSee(self::ANONYMISE_BUTTON);
        $this->post("/admin/clients/{$client->id}/anonymise", ['confirm' => '1'])->assertSessionHasErrors('anonymise');
    }
}
