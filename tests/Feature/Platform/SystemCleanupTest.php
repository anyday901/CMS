<?php

namespace Tests\Feature\Platform;

use App\Enums\StaffRole;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Department;
use App\Models\EmailLog;
use App\Models\GatewayLog;
use App\Models\Invoice;
use App\Models\LoginAttempt;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\Transaction;
use App\Models\User;
use App\System\SystemCleanup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SystemCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/cleanup';

    private const BEFORE = '2026-01-01';

    private const OLD = '2025-06-01 12:00:00';

    private const RECENT = '2026-03-01 12:00:00';

    private const OLD_PATH = 'tickets/1/old-file';

    private const NEW_PATH = 'tickets/1/new-file';

    private const OLD_ENTRY = 'Old entry';

    private const COMMAND = 'system:cleanup';

    private const DISK = 'local';

    private const TARGETS = 'targets';

    private const BEFORE_KEY = 'before';

    private const MADE = 'created_at';

    private const DESCRIPTION = 'description';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-01 09:00:00');
        Storage::fake(self::DISK);
    }

    public function test_staff_see_counts_and_delete_only_what_they_ticked(): void
    {
        $this->seedData();
        $this->actingAs(User::factory()->create());

        $this->get(self::URL.'?before='.self::BEFORE)->assertOk()
            ->assertSee('System cleanup')
            ->assertSeeInOrder(['Ticket attachments', '1', 'Email log', '1', 'Activity log']);
        $this->assertSame(1, app(SystemCleanup::class)->preview(now()->parse(self::BEFORE))[SystemCleanup::ATTACHMENTS]);

        $this->delete(self::URL, [self::BEFORE_KEY => self::BEFORE, self::TARGETS => [SystemCleanup::ATTACHMENTS, SystemCleanup::EMAILS, 'gateway_log', 'login_attempts']])
            ->assertRedirect(self::URL.'?before='.self::BEFORE)
            ->assertSessionHas('status', 'Deleted 5 items from before Jan 1, 2026.');

        Storage::disk(self::DISK)->assertMissing(self::OLD_PATH);
        Storage::disk(self::DISK)->assertExists(self::NEW_PATH);
        $this->assertSame([self::NEW_PATH], TicketAttachment::pluck('path')->all());
        $this->assertSame(1, EmailLog::count());
        $this->assertSame(1, GatewayLog::count());
        $this->assertSame(1, LoginAttempt::count());

        // The activity log wasn't ticked, so its old entry stays, and the cleanup is recorded.
        $this->assertTrue(Activity::where(self::DESCRIPTION, self::OLD_ENTRY)->exists());
        $entry = Activity::where(self::DESCRIPTION, 'Cleaned up old data from before '.self::BEFORE)->sole();
        $this->assertSame(['attachments' => 1, 'emails' => 1, 'gateway_log' => 1, 'login_attempts' => 2], $entry->properties['deleted']);

        // Financial records are never touched.
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Transaction::count());
    }

    public function test_cleanup_checks_its_input_and_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $this->from(self::URL)->delete(self::URL, [self::BEFORE_KEY => self::BEFORE])->assertSessionHasErrors(self::TARGETS);
        $this->from(self::URL)->delete(self::URL, [self::BEFORE_KEY => self::BEFORE, self::TARGETS => ['invoices']])->assertSessionHasErrors('targets.0');
        $this->from(self::URL)->delete(self::URL, [self::BEFORE_KEY => '2027-01-01', self::TARGETS => [SystemCleanup::EMAILS]])->assertSessionHasErrors(self::BEFORE_KEY);
        $this->get(self::URL.'?before=2027-01-01')->assertSessionHasErrors(self::BEFORE_KEY);

        $this->actingAs(User::factory()->create(['role' => StaffRole::Billing]));
        $this->get(self::URL)->assertForbidden();
        $this->delete(self::URL, [self::BEFORE_KEY => self::BEFORE, self::TARGETS => [SystemCleanup::EMAILS]])->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => StaffRole::Custom, 'permissions' => ['manage-system']]));
        $this->get(self::URL)->assertOk();
    }

    public function test_the_command_counts_then_deletes(): void
    {
        $this->seedData();

        $this->artisan(self::COMMAND, ['--before' => self::BEFORE, '--dry-run' => true])->expectsOutputToContain('Nothing deleted.')->assertSuccessful();
        $this->assertSame(2, EmailLog::count());

        $this->artisan(self::COMMAND, ['--before' => self::BEFORE, '--only' => [SystemCleanup::ACTIVITY], '--force' => true])->assertSuccessful();
        $this->assertFalse(Activity::where(self::DESCRIPTION, self::OLD_ENTRY)->exists());
        $this->assertSame(2, EmailLog::count());

        $this->artisan(self::COMMAND, ['--days' => 30, '--force' => true])->expectsOutputToContain('Deleted')->assertSuccessful();
        $this->assertSame(0, EmailLog::count());
        Storage::disk(self::DISK)->assertMissing(self::NEW_PATH);
        $this->assertSame(1, Invoice::count());

        $this->artisan(self::COMMAND)->expectsOutputToContain('--days=N')->assertFailed();
        $this->artisan(self::COMMAND, ['--days' => 1, '--only' => ['invoices']])->expectsOutputToContain('Unknown cleanup target')->assertFailed();
    }

    private function seedData(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => '2025-01-01', 'due_date' => '2025-01-15', 'total' => 1000]);
        Transaction::create(['client_id' => $client->id, 'invoice_id' => $invoice->id, 'gateway' => 'paypal', 'currency' => 'USD', 'amount' => 1000]);
        foreach ([Invoice::query(), Transaction::query()] as $query) {
            $query->update([self::MADE => self::OLD]);
        }

        $department = Department::create(['name' => 'Support']);
        $ticket = Ticket::create(['client_id' => $client->id, 'department_id' => $department->id, 'subject' => 'Help']);
        $message = $ticket->messages()->create(['body' => 'Hello', 'client_id' => $client->id]);
        foreach ([self::OLD_PATH => self::OLD, self::NEW_PATH => self::RECENT] as $path => $at) {
            Storage::disk(self::DISK)->put($path, 'file');
            $message->attachments()->create(['path' => $path, 'name' => 'a.txt', 'size' => 4])->forceFill([self::MADE => $at])->save();
        }

        foreach ([self::OLD, self::RECENT] as $at) {
            EmailLog::create(['client_id' => $client->id, 'template' => 'invoice_created', 'to' => 'a@example.com', 'subject' => 'Hi', 'body' => 'Hi', 'status' => EmailLog::SENT])->forceFill([self::MADE => $at])->save();
            GatewayLog::create(['gateway' => 'stripe', 'endpoint' => 'webhook', 'outcome' => GatewayLog::ACCEPTED, 'response_status' => 200, 'invoice_id' => $invoice->id])->forceFill([self::MADE => $at])->save();
        }
        foreach ([[LoginAttempt::FAILED, self::OLD], [LoginAttempt::SUCCESS, self::OLD], [LoginAttempt::SUCCESS, self::RECENT]] as [$outcome, $at]) {
            LoginAttempt::create(['area' => LoginAttempt::ADMIN, 'email' => 'a@example.com', 'outcome' => $outcome, 'created_at' => $at]);
        }
        Activity::record(self::OLD_ENTRY)->forceFill([self::MADE => self::OLD])->save();
    }
}
