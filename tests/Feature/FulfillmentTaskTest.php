<?php

namespace Tests\Feature;

use App\Billing\ServiceLifecycle;
use App\Enums\StaffRole;
use App\Models\FulfillmentTask;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FulfillmentTaskTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->product = Product::factory()->create(['module' => 'manual', 'module_config' => [
            'create_checklist' => "Create the cPanel account\n\n  Email the login details  ",
        ]]);
    }

    public function test_activating_a_service_opens_a_set_up_task_with_the_products_checklist(): void
    {
        $staff = User::factory()->create();
        $service = Service::factory()->for($this->product)->pending()->create();

        app(ServiceLifecycle::class)->activate($service);

        $task = FulfillmentTask::sole();
        $this->assertSame('create', $task->action);
        $this->assertSame([
            ['text' => 'Create the cPanel account', 'done' => false],
            ['text' => 'Email the login details', 'done' => false],
        ], $task->checklist);
        $this->assertSame('done', $service->fresh()->provisioning_status);
        Notification::assertSentTo($staff, StaffAlert::class, fn (StaffAlert $alert) => $alert->url === route('admin.tasks.show', $task));
    }

    public function test_actions_without_a_checklist_get_a_default_step_and_repeats_do_not_duplicate(): void
    {
        $service = Service::factory()->for($this->product)->create();

        app(ServiceLifecycle::class)->suspend($service, 'overdue');
        $this->actingAs(User::factory()->create(), 'web')
            ->post("/admin/services/{$service->id}/provision", ['action' => 'suspend'])->assertRedirect();

        $task = FulfillmentTask::sole();
        $this->assertSame([['text' => 'Suspend the service', 'done' => false]], $task->checklist);
    }

    public function test_staff_tick_steps_and_mark_the_task_done(): void
    {
        $service = Service::factory()->for($this->product)->pending()->create();
        app(ServiceLifecycle::class)->activate($service);
        $task = FulfillmentTask::sole();
        $staff = User::factory()->create(['name' => 'Corey']);
        $this->actingAs($staff, 'web');

        $this->get('/admin')->assertSee('Open tasks')->assertSee('Set up');
        $this->get('/admin/tasks')->assertSee("#{$task->id} Set up");

        $this->put("/admin/tasks/{$task->id}", ['done' => [0], 'complete' => '1'])->assertSessionHasErrors('done');
        $this->assertTrue($task->fresh()->checklist[0]['done']);
        $this->assertTrue($task->fresh()->isOpen());

        $this->put("/admin/tasks/{$task->id}", ['done' => [0, 1], 'notes' => 'Account cp123', 'complete' => '1'])
            ->assertRedirect('/admin/tasks');

        $task->refresh();
        $this->assertFalse($task->isOpen());
        $this->assertSame($staff->id, $task->completed_by);
        $this->assertSame('Account cp123', $task->notes);
        $this->get('/admin/tasks?status=done')->assertSee('by Corey');
        $this->put("/admin/tasks/{$task->id}", ['done' => []])->assertStatus(422);
    }

    public function test_support_staff_can_see_tasks_but_not_change_them(): void
    {
        $service = Service::factory()->for($this->product)->pending()->create();
        app(ServiceLifecycle::class)->activate($service);
        $task = FulfillmentTask::sole();
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');

        $this->get("/admin/tasks/{$task->id}")->assertOk()->assertDontSee('Mark done');
        $this->put("/admin/tasks/{$task->id}", ['done' => [0, 1], 'complete' => '1'])->assertForbidden();
    }

    public function test_the_product_form_shows_checklists_as_text_areas(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->get("/admin/products/{$this->product->id}/edit")
            ->assertSee('Manual fulfillment: Set-up checklist')
            ->assertSee('<textarea name="module_config[manual][create_checklist]"', false);
    }
}
