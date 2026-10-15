<?php

namespace Tests\Feature\Addons;

use App\Addons\AddonManager;
use App\Addons\ProjectManagement\Duration;
use App\Addons\ProjectManagement\Models\Project;
use App\Addons\ProjectManagement\Models\Task;
use App\Addons\ProjectManagement\Models\TimeEntry;
use App\Addons\ProjectManagement\Models\Timer;
use App\Billing\InvoiceCanceller;
use App\Billing\InvoiceEditor;
use App\Billing\PaymentRecorder;
use App\Clients\ClientMerger;
use App\Enums\InvoiceStatus;
use App\Enums\StaffRole;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\TaxRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Client $client;

    /** @var list<HookEvent> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->staff = User::factory()->create(['name' => 'Sam Staff']);
        $this->actingAs($this->staff, 'web');
        $this->client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'currency' => 'USD']);

        $addons = app(AddonManager::class);
        $addons->install('projects');
        $addons->setEnabled('projects', true);
        // The app booted before the add-on was enabled, so boot it as the next request would.
        $addons->find('projects')->boot();
        app(Hooks::class)->listen(Hooks::ANY, function (HookEvent $event) {
            $this->fired[] = $event;
        });
    }

    private function project(array $attributes = []): Project
    {
        return Project::create($attributes + ['title' => 'Website redesign', 'client_id' => $this->client->id, 'user_id' => $this->staff->id, 'hourly_rate' => 9000]);
    }

    private function firedKeys(): array
    {
        return array_map(fn (HookEvent $event) => $event->key, $this->fired);
    }

    public function test_pages_answer_404_while_the_add_on_is_disabled_or_not_installed(): void
    {
        $project = $this->project();
        $this->get('/admin/projects')->assertOk();

        app(AddonManager::class)->setEnabled('projects', false);
        $this->get('/admin/projects')->assertNotFound();
        $this->get("/admin/projects/{$project->id}")->assertNotFound();

        app(AddonManager::class)->uninstall('projects', removeData: true);
        $this->get('/admin/projects')->assertNotFound();
        $this->get("/admin/projects/{$project->id}")->assertNotFound();
    }

    public function test_staff_create_edit_and_delete_projects(): void
    {
        $this->get("/admin/projects/create?client={$this->client->id}")->assertOk()->assertSee('75.00');

        $this->post('/admin/projects', [
            'title' => 'Shop launch',
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'status' => Project::PLANNED,
            'due_on' => today()->addMonth()->toDateString(),
            'hourly_rate' => '80.00',
            'description' => 'New online shop',
        ])->assertRedirect();

        $project = Project::sole();
        $this->assertSame(8000, $project->hourly_rate);
        $this->assertSame(Project::PLANNED, $project->status);
        $this->assertContains('project.created', $this->firedKeys());

        $this->get('/admin/projects')->assertOk()->assertSee('Shop launch')->assertSee('Ada Lovelace');
        $this->get("/admin/projects/{$project->id}")->assertOk()->assertSee('New online shop')->assertSee('$80.00');

        $this->get("/admin/projects/{$project->id}/edit")->assertOk()->assertSee('Shop launch');
        $this->put("/admin/projects/{$project->id}", ['title' => 'Shop launch', 'client_id' => $this->client->id, 'status' => Project::COMPLETED, 'hourly_rate' => '80'])->assertRedirect();
        $this->assertNotNull($project->fresh()->completed_at);
        $this->assertContains('project.completed', $this->firedKeys());

        $this->delete("/admin/projects/{$project->id}")->assertRedirect('/admin/projects');
        $this->assertSame(0, Project::count());
    }

    public function test_the_list_filters_by_status_client_and_owner(): void
    {
        $other = User::factory()->create();
        $this->project(['title' => 'Mine and active']);
        $this->project(['title' => 'Someone else', 'user_id' => $other->id, 'client_id' => null]);
        $this->project(['title' => 'All done', 'status' => Project::COMPLETED]);

        $this->get('/admin/projects')->assertSee('Mine and active')->assertSee('Someone else')->assertDontSee('All done');
        $this->get('/admin/projects?status=completed')->assertSee('All done')->assertDontSee('Mine and active');
        $this->get('/admin/projects?mine=1')->assertSee('Mine and active')->assertDontSee('Someone else');
        $this->get("/admin/projects?status=all&client={$this->client->id}")->assertSee('All done')->assertDontSee('Someone else');
    }

    public function test_only_staff_who_manage_clients_create_projects_but_anyone_logs_time(): void
    {
        $project = $this->project();
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');

        $this->get('/admin/projects/create')->assertForbidden();
        $this->post('/admin/projects', ['title' => 'Nope', 'status' => 'active'])->assertForbidden();
        $this->post("/admin/projects/{$project->id}/invoice")->assertForbidden();
        $this->post("/admin/projects/{$project->id}/time", ['worked_on' => today()->toDateString(), 'time' => '30m', 'billable' => '1'])->assertRedirect();
        $this->assertSame(30, TimeEntry::sole()->minutes);
    }

    public function test_tasks_are_added_ticked_off_reordered_and_deleted(): void
    {
        $project = $this->project();
        $this->post("/admin/projects/{$project->id}/tasks", ['title' => 'Wireframes', 'notes' => 'Secret staff note', 'user_id' => $this->staff->id])->assertRedirect();
        $this->post("/admin/projects/{$project->id}/tasks", ['title' => 'Design', 'due_on' => today()->addWeek()->toDateString()])->assertRedirect();
        [$wireframes, $design] = $project->tasks()->get()->all();
        $this->assertSame(['Wireframes', 'Design'], $project->tasks()->pluck('title')->all());

        $this->put("/admin/projects/tasks/{$design->id}/move", ['direction' => 'up'])->assertRedirect();
        $this->assertSame(['Design', 'Wireframes'], $project->tasks()->pluck('title')->all());

        $this->put("/admin/projects/tasks/{$wireframes->id}/status", ['status' => Task::DONE])->assertRedirect();
        $this->assertNotNull($wireframes->fresh()->completed_at);
        $taskEvent = collect($this->fired)->firstWhere('key', 'project.task_completed');
        $this->assertSame('Wireframes', $taskEvent->data['task']['title']);
        $this->assertSame($project->id, $taskEvent->data['project']['id']);

        $this->put("/admin/projects/tasks/{$design->id}", ['title' => 'Visual design', 'status' => Task::IN_PROGRESS])->assertRedirect();
        $this->assertSame('Visual design', $design->fresh()->title);
        $this->get("/admin/projects/tasks/{$design->id}/edit")->assertOk();

        $this->delete("/admin/projects/tasks/{$design->id}")->assertRedirect();
        $this->assertSame(['Wireframes'], $project->tasks()->pluck('title')->all());
    }

    public function test_time_is_logged_edited_and_deleted(): void
    {
        $project = $this->project();
        $task = $project->tasks()->create(['title' => 'Build']);

        $this->post("/admin/projects/{$project->id}/time", ['worked_on' => '2026-10-01', 'time' => '1:30', 'task_id' => $task->id, 'description' => 'Header', 'billable' => '1'])->assertRedirect();
        $entry = TimeEntry::sole();
        $this->assertSame(90, $entry->minutes);
        $this->assertSame(9000, $entry->rate);
        $this->assertSame($this->staff->id, $entry->user_id);
        $this->assertSame(13500, $entry->amount());

        $this->get("/admin/projects/time/{$entry->id}/edit")->assertOk()->assertSee('1h 30m');
        $this->post("/admin/projects/{$project->id}/time", ['worked_on' => '2026-10-01', 'time' => 'lots'])->assertSessionHasErrors('time');

        $this->put("/admin/projects/time/{$entry->id}", ['worked_on' => '2026-10-02', 'time' => '2h', 'rate' => '100', 'billable' => '0'])->assertRedirect();
        $entry->refresh();
        $this->assertSame(120, $entry->minutes);
        $this->assertSame(10000, $entry->rate);
        $this->assertFalse($entry->billable);
        $this->assertSame(0, $entry->amount());

        $this->get("/admin/projects/{$project->id}")->assertSee('2h');
        $this->delete("/admin/projects/time/{$entry->id}")->assertRedirect();
        $this->assertSame(0, TimeEntry::count());
    }

    public function test_time_typed_in_different_ways_becomes_minutes(): void
    {
        foreach (['1:30' => 90, '1.5h' => 90, '1.5' => 90, '2' => 120, '90m' => 90, '45 min' => 45, '2h 15m' => 135, '2h15' => 135, '.25h' => 15, '0:05' => 5] as $input => $minutes) {
            $this->assertSame($minutes, Duration::parse($input), $input);
        }

        foreach (['', 'abc', '0', '25h', '1:75', '-1h'] as $bad) {
            try {
                Duration::parse($bad);
                $this->fail("{$bad} should not parse");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('1h 30m', Duration::format(90));
        $this->assertSame('2h', Duration::format(120));
        $this->assertSame('45m', Duration::format(45));
        // 50 minutes at $90.00 an hour is $75.00; 1 minute at $1.00 an hour rounds to 2 cents.
        $this->assertSame(7500, Duration::cost(50, 9000));
        $this->assertSame(2, Duration::cost(1, 100));
    }

    public function test_the_timer_starts_stops_and_logs_time(): void
    {
        $project = $this->project();
        $this->travelTo(now()->setTime(9, 0));
        $this->post("/admin/projects/{$project->id}/timer", ['description' => 'Bug fixing'])->assertRedirect();
        $this->assertSame(1, Timer::count());
        $this->get('/admin/projects')->assertSee('Your timer is running');

        $this->travel(47)->minutes();
        $this->post('/admin/projects/timer/stop')->assertRedirect();

        $entry = TimeEntry::sole();
        $this->assertSame(47, $entry->minutes);
        $this->assertSame('Bug fixing', $entry->description);
        $this->assertTrue($entry->billable);
        $this->assertSame(0, Timer::count());

        // Starting a timer while one runs logs the first one.
        $this->post("/admin/projects/{$project->id}/timer");
        $this->travel(10)->minutes();
        $this->post("/admin/projects/{$project->id}/timer");
        $this->assertSame(2, TimeEntry::count());
        $this->delete('/admin/projects/timer')->assertRedirect();
        $this->assertSame(0, Timer::count());
    }

    public function test_unbilled_time_becomes_a_draft_invoice_with_tax(): void
    {
        TaxRule::create(['name' => 'Sales tax', 'rate' => 1000, 'country' => '', 'state' => '']);
        $project = $this->project();
        $task = $project->tasks()->create(['title' => 'Build']);
        $first = $project->timeEntries()->create(['worked_on' => '2026-10-01', 'minutes' => 90, 'description' => 'Header', 'rate' => 9000, 'user_id' => $this->staff->id]);
        $second = $project->timeEntries()->create(['worked_on' => '2026-10-02', 'minutes' => 20, 'task_id' => $task->id, 'rate' => 9000]);
        $internal = $project->timeEntries()->create(['worked_on' => '2026-10-02', 'minutes' => 60, 'billable' => false, 'rate' => 9000]);

        $this->get("/admin/projects/{$project->id}")->assertSee('Invoice unbilled time ($165.00)');
        $response = $this->post("/admin/projects/{$project->id}/invoice");

        $invoice = Invoice::sole();
        $response->assertRedirect("/admin/invoices/{$invoice->id}");
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame($this->client->id, $invoice->client_id);
        $this->assertSame(16500, $invoice->subtotal);
        $this->assertSame(1650, $invoice->tax);
        $this->assertSame(18150, $invoice->total);

        $items = $invoice->items()->orderBy('id')->get();
        $this->assertSame(['Website redesign: Header (Oct 1, 2026, 1h 30m at $90.00/hr)', 'Website redesign: Build (Oct 2, 2026, 20m at $90.00/hr)'], $items->pluck('description')->all());
        $this->assertSame([13500, 3000], $items->pluck('amount')->all());

        $this->assertSame($items[0]->id, $first->fresh()->invoice_item_id);
        $this->assertSame($invoice->id, $second->fresh()->invoice_id);
        $this->assertNull($internal->fresh()->invoice_id);
        $this->assertTrue($project->invoices()->whereKey($invoice->id)->exists());
        $this->assertContains('project.invoiced', $this->firedKeys());

        // Nothing is left to bill, and billed time can't be changed.
        $this->post("/admin/projects/{$project->id}/invoice")->assertSessionHasErrors('invoice');
        $this->delete("/admin/projects/time/{$first->id}")->assertSessionHasErrors('entry');
        $this->get("/admin/projects/{$project->id}")->assertSee('#'.$invoice->number)->assertDontSee('Invoice unbilled time');

        // Staff publish it as usual, and the project shows it paid once it is.
        app(InvoiceEditor::class)->publish($invoice);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        app(PaymentRecorder::class)->record($invoice->fresh(), 18150, 'bank_transfer', null);
        $this->get("/admin/projects/{$project->id}")->assertSeeInOrder(['#'.$invoice->number, 'Paid']);
    }

    public function test_cancelling_the_invoice_or_removing_a_line_frees_the_time(): void
    {
        $project = $this->project();
        $first = $project->timeEntries()->create(['worked_on' => '2026-10-01', 'minutes' => 60, 'rate' => 9000]);
        $second = $project->timeEntries()->create(['worked_on' => '2026-10-02', 'minutes' => 30, 'rate' => 9000]);
        $this->post("/admin/projects/{$project->id}/invoice");
        $invoice = Invoice::sole();

        // Removing a line from the draft frees that entry only.
        $keep = $invoice->items()->where('id', $second->fresh()->invoice_item_id)->sole();
        app(InvoiceEditor::class)->update($invoice, ['issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(), 'notes' => null, 'items' => [
            ['id' => $keep->id, 'description' => $keep->description, 'amount' => $keep->amount, 'taxable' => true],
        ]]);
        $this->assertNull($first->fresh()->invoice_item_id);
        $this->assertSame(1, TimeEntry::unbilled()->count());

        // Cancelling the invoice frees the rest, and they can be invoiced again.
        app(InvoiceCanceller::class)->cancel($invoice);
        $this->assertSame(2, TimeEntry::unbilled()->count());
        $this->assertFalse($second->fresh()->isBilled());

        $this->post("/admin/projects/{$project->id}/invoice")->assertRedirect();
        $again = Invoice::latest('id')->first();
        $this->assertNotSame($invoice->id, $again->id);
        $this->assertSame(13500, $again->subtotal);
        $this->assertSame($again->id, $second->fresh()->invoice_id);
    }

    public function test_internal_projects_and_projects_without_billable_time_cannot_be_invoiced(): void
    {
        $internal = $this->project(['client_id' => null]);
        $internal->timeEntries()->create(['worked_on' => today(), 'minutes' => 60, 'rate' => 9000]);
        $this->post("/admin/projects/{$internal->id}/invoice")->assertSessionHasErrors('invoice');

        $free = $this->project(['hourly_rate' => 0]);
        $free->timeEntries()->create(['worked_on' => today(), 'minutes' => 60, 'rate' => 0]);
        $this->post("/admin/projects/{$free->id}/invoice")->assertSessionHasErrors('invoice');
        $this->assertSame(0, Invoice::count());
    }

    public function test_staff_link_and_unlink_a_clients_invoice(): void
    {
        $project = $this->project();
        $deposit = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $someoneElses = Invoice::create(['client_id' => Client::factory()->create()->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);

        $this->post("/admin/projects/{$project->id}/invoices", ['number' => $someoneElses->number])->assertSessionHasErrors('number');
        $this->post("/admin/projects/{$project->id}/invoices", ['number' => '#'.$deposit->number])->assertRedirect();
        $this->assertTrue($project->invoices()->whereKey($deposit->id)->exists());

        $this->delete("/admin/projects/{$project->id}/invoices/{$deposit->id}")->assertRedirect();
        $this->assertFalse($project->invoices()->exists());
    }

    public function test_the_time_report_adds_up_time_in_a_date_range(): void
    {
        $project = $this->project();
        $project->timeEntries()->create(['worked_on' => '2026-10-03', 'minutes' => 90, 'rate' => 9000, 'user_id' => $this->staff->id]);
        $project->timeEntries()->create(['worked_on' => '2026-10-04', 'minutes' => 30, 'billable' => false, 'user_id' => $this->staff->id]);
        $project->timeEntries()->create(['worked_on' => '2026-09-01', 'minutes' => 600, 'user_id' => $this->staff->id]);

        $this->get('/admin/projects/time?from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertSeeInOrder(['Total logged', '2h', 'Billable', '1h 30m', 'Not billable', '30m'])
            ->assertSee('$135.00')->assertSee('Sam Staff');
    }

    public function test_clients_see_their_projects_only_when_the_setting_is_on(): void
    {
        $project = $this->project(['description' => 'Your new website']);
        $project->tasks()->create(['title' => 'Wireframes', 'notes' => 'Client is slow to reply']);
        $project->timeEntries()->create(['worked_on' => today(), 'minutes' => 75, 'description' => 'Kickoff call', 'rate' => 9000]);
        $theirs = Project::create(['title' => 'Someone else\'s project', 'client_id' => Client::factory()->create()->id]);
        $this->actingAs($this->client, 'client');

        $this->get('/portal/projects')->assertNotFound();
        $this->assertSame([], app(AddonManager::class)->portalLinks());

        app(AddonManager::class)->saveSettings('projects', ['portal' => true, 'portal_time' => false]);
        $this->assertSame(['portal.projects.index' => 'Projects'], app(AddonManager::class)->portalLinks());
        $this->get('/portal/projects')->assertOk()->assertSee('Website redesign')->assertDontSee('Someone else');
        $this->get("/portal/projects/{$project->id}")->assertOk()
            ->assertSee('Your new website')->assertSee('Wireframes')
            ->assertDontSee('Client is slow to reply')->assertDontSee('$90.00')->assertDontSee('Kickoff call');
        $this->get("/portal/projects/{$theirs->id}")->assertNotFound();

        app(AddonManager::class)->saveSettings('projects', ['portal' => true, 'portal_time' => true]);
        $this->get("/portal/projects/{$project->id}")->assertSee('Kickoff call')->assertSee('1h 15m')->assertDontSee('$90.00');
    }

    public function test_the_add_on_defines_its_events_for_webhooks_and_chat_channels(): void
    {
        $events = app(Hooks::class)->events();

        foreach (['project.created', 'project.completed', 'project.task_completed', 'project.invoiced', 'client.merged'] as $key) {
            $this->assertArrayHasKey($key, $events);
        }
        $this->assertSame(['admin.projects.index' => 'Projects'], app(AddonManager::class)->adminLinks($this->staff));
    }

    public function test_merging_clients_moves_their_projects(): void
    {
        $keep = Client::factory()->create(['currency' => 'USD']);
        $project = $this->project();
        $merged = null;
        app(Hooks::class)->listen('client.merged', function (HookEvent $event) use (&$merged) {
            $merged = $event->data;
        });

        app(ClientMerger::class)->merge($this->client, $keep);

        $this->assertNull(Client::find($this->client->id));
        $this->assertSame($keep->id, $project->fresh()->client_id);
        $this->assertSame(['from_client_id' => $this->client->id, 'into_client_id' => $keep->id], $merged);
    }

    public function test_uninstalling_with_data_removes_the_tables(): void
    {
        $this->project();
        $this->assertTrue(Schema::hasTable('pm_projects'));

        app(AddonManager::class)->uninstall('projects', removeData: true);

        foreach (['pm_projects', 'pm_tasks', 'pm_time_entries', 'pm_timers', 'pm_project_invoice'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        // Installing again starts afresh.
        app(AddonManager::class)->install('projects');
        $this->assertSame(0, Project::count());
    }
}
