<?php

namespace Tests\Feature\Admin;

use App\Enums\StaffRole;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\ClientNote;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Todo;
use App\Models\User;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Client groups, notes, the staff to-do list and the admin search box. */
class ClientToolsTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENTS = '/admin/clients';

    private const GROUPS = '/admin/client-groups';

    private const TODOS = '/admin/todos';

    private const RESELLERS = 'Resellers';

    private const ADA = 'Ada Lovelace';

    private const GRACE = 'Grace Hopper';

    private const PHONE_NOTE = 'Prefers phone calls';

    private const DISCOUNT_NOTE = 'Asked about a discount';

    private const SSL_TODO = 'Renew SSL certificate';

    private const CALL_TODO = 'Call Ada back';

    private const OLIVE_TODO = 'Olive\'s job';

    private const TICKET = 'Compiler is down';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['name' => 'Sam Staff']);
        $this->actingAs($this->staff);
    }

    public function test_staff_sort_clients_into_groups_and_filter_by_them(): void
    {
        $this->post(self::GROUPS, ['name' => self::RESELLERS, 'colour' => '#7c3aed', 'description' => 'Sell our hosting on'])->assertRedirect(self::GROUPS);
        $this->post(self::GROUPS, ['name' => self::RESELLERS, 'colour' => '#7c3aed'])->assertSessionHasErrors('name');
        $this->post(self::GROUPS, ['name' => 'VIP', 'colour' => 'red'])->assertSessionHasErrors('colour');
        $group = ClientGroup::sole();

        $ada = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        Client::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $this->put(self::CLIENTS."/{$ada->id}", [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => $ada->email, 'status' => 'active', 'client_group_id' => $group->id,
        ])->assertRedirect();
        $this->assertSame($group->id, $ada->fresh()->client_group_id);

        $this->get(self::CLIENTS."/{$ada->id}")->assertSee(self::RESELLERS);
        $this->get(self::CLIENTS.'?group='.$group->id)->assertSee(self::ADA)->assertDontSee(self::GRACE)->assertSee('1 client found');
        $this->get(self::CLIENTS.'?group=none')->assertSee(self::GRACE)->assertDontSee(self::ADA);
        $this->get(self::GROUPS)->assertSee(self::RESELLERS)->assertSee('Sell our hosting on');

        // Deleting a group keeps its clients.
        $this->delete(self::GROUPS."/{$group->id}")->assertRedirect(self::GROUPS);
        $this->assertNull($ada->fresh()->client_group_id);
    }

    public function test_only_settings_staff_manage_groups(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]));
        $this->get(self::GROUPS)->assertForbidden();
    }

    public function test_staff_add_pin_edit_and_delete_client_notes(): void
    {
        $client = Client::factory()->create();

        $this->post(self::CLIENTS."/{$client->id}/notes", ['body' => self::PHONE_NOTE, 'pinned' => '1'])->assertRedirect();
        $this->post(self::CLIENTS."/{$client->id}/notes", ['body' => self::DISCOUNT_NOTE])->assertRedirect();
        $this->post(self::CLIENTS."/{$client->id}/notes", ['body' => ''])->assertSessionHasErrors('body');

        $pinned = ClientNote::where('pinned', true)->sole();
        $this->assertSame($this->staff->id, $pinned->user_id);
        $this->get(self::CLIENTS."/{$client->id}")->assertSeeInOrder([self::PHONE_NOTE, 'Notes', self::PHONE_NOTE, self::DISCOUNT_NOTE])->assertSee('Sam Staff');

        $this->put("/admin/client-notes/{$pinned->id}", ['body' => 'Prefers email now'])->assertRedirect();
        $this->assertFalse($pinned->fresh()->pinned);
        $this->delete("/admin/client-notes/{$pinned->id}")->assertRedirect();
        $this->assertSame(1, ClientNote::count());

        // Staff who can only look see notes but can't change them.
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]));
        $this->get(self::CLIENTS."/{$client->id}")->assertSee(self::DISCOUNT_NOTE)->assertDontSee('Add a note');
        $this->post(self::CLIENTS."/{$client->id}/notes", ['body' => 'x'])->assertForbidden();
    }

    public function test_the_to_do_list(): void
    {
        $other = User::factory()->create(['name' => 'Olive Other']);
        $client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->post(self::TODOS, ['title' => self::SSL_TODO, 'user_id' => $this->staff->id, 'due_on' => today()->subDay()->toDateString()])->assertRedirect(self::TODOS);
        $this->post(self::TODOS, ['title' => self::CALL_TODO, 'client_id' => $client->id, 'from' => 'client'])->assertRedirect(self::CLIENTS."/{$client->id}#todos");
        $this->post(self::TODOS, ['title' => self::OLIVE_TODO, 'user_id' => $other->id])->assertRedirect();
        $this->post(self::TODOS, ['title' => ''])->assertSessionHasErrors('title');

        // Mine by default; everyone's and unassigned on tabs.
        $this->get(self::TODOS)->assertSee(self::SSL_TODO)->assertSee('overdue')->assertDontSee(self::CALL_TODO)->assertDontSee(self::OLIVE_TODO);
        $this->get(self::TODOS.'?who=all')->assertSee(self::CALL_TODO)->assertSee(self::OLIVE_TODO);
        $this->get(self::TODOS.'?who=unassigned')->assertSee(self::CALL_TODO)->assertDontSee(self::OLIVE_TODO);
        $this->get(self::TODOS.'?who=all&show=overdue')->assertSee(self::SSL_TODO)->assertDontSee(self::CALL_TODO);

        // The menu counts mine; the dashboard and the client's page list them.
        $this->get('/admin')->assertSee('To-do (1)')->assertSee('Your to-do list')->assertSee(self::SSL_TODO)->assertSee(self::CALL_TODO)
            ->assertViewHas('todos', fn ($todos) => ! $todos->contains('title', self::OLIVE_TODO));
        $this->get(self::CLIENTS."/{$client->id}")->assertSee(self::CALL_TODO);

        $todo = Todo::where('title', self::SSL_TODO)->sole();
        $this->put(self::TODOS."/{$todo->id}/status", ['status' => 'done'])->assertSessionHas('status');
        $this->assertNotNull($todo->fresh()->completed_at);
        $this->get(self::TODOS)->assertDontSee(self::SSL_TODO);
        $this->get(self::TODOS.'?show=done')->assertSee(self::SSL_TODO);

        $this->put(self::TODOS."/{$todo->id}", ['title' => 'Renew SSL certificates', 'status' => 'in_progress'])->assertRedirect(self::TODOS);
        $this->assertSame([Todo::IN_PROGRESS, null], [$todo->fresh()->status, $todo->fresh()->completed_at]);
        $this->delete(self::TODOS."/{$todo->id}")->assertRedirect(self::TODOS);
        $this->assertSame(2, Todo::count());
    }

    public function test_global_search_finds_clients_services_invoices_and_tickets(): void
    {
        Mail::fake();
        $ada = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'company' => 'Analytical Ltd', 'email' => 'ada@engines.example']);
        $grace = Client::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@navy.example']);
        $grace->contacts()->create(['first_name' => 'Bob', 'last_name' => 'Books', 'email' => 'bob@ledger.example']);
        $service = Service::factory()->for($ada)->create(['label' => 'engines.example']);
        $invoice = Invoice::create(['client_id' => $ada->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $ticket = app(TicketDesk::class)->open($grace, Department::create(['name' => 'Support']), self::TICKET, 'Help');

        $this->get('/admin/search?q=ada+lovelace')->assertOk()->assertSee(self::ADA)->assertDontSee(self::GRACE);
        $this->get('/admin/search?q=engines.example')->assertSee(self::ADA)->assertSee('Services')->assertSee(route('admin.services.show', $service));
        $this->get('/admin/search?q=ledger')->assertSee(self::GRACE);
        $this->get('/admin/search?q=compiler')->assertSee(self::TICKET)->assertSee('1 result');
        $this->get('/admin/search?q='.$invoice->number)->assertSee(route('admin.invoices.show', $invoice));
        $this->get("/admin/search?q=%23{$ticket->id}")->assertSee(self::TICKET);
        $this->get('/admin/search?q=zzzz')->assertSee('Nothing matched');
        $this->get('/admin/search?q=a')->assertSee('Type at least two letters');
        // Wildcards are searched for, not used.
        $this->get('/admin/search?q=%25%25')->assertSee('0 results');

        // Tickets only for staff who can open them.
        $this->actingAs(User::factory()->create(['role' => StaffRole::Custom, 'permissions' => ['manage-billing']]));
        $this->get('/admin/search?q=compiler')->assertDontSee(self::TICKET);
    }
}
