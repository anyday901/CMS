<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\CannedReply;
use App\Models\Client;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CannedReplyTest extends TestCase
{
    use RefreshDatabase;

    private const REPLIES = '/admin/canned-replies';

    public function test_staff_manage_canned_replies(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]));

        $this->post(self::REPLIES, ['title' => 'Reset steps', 'body' => 'Hi {client_first_name}, try this.'])->assertRedirect(self::REPLIES);
        $reply = CannedReply::sole();
        $this->get(self::REPLIES)->assertOk()->assertSee('Reset steps');
        $this->get(self::REPLIES."/{$reply->id}/edit")->assertOk()->assertSee('try this.');

        $this->put(self::REPLIES."/{$reply->id}", ['title' => 'Password reset', 'body' => 'New text'])->assertRedirect(self::REPLIES);
        $this->assertSame(['Password reset', 'New text'], [$reply->fresh()->title, $reply->fresh()->body]);

        $this->post(self::REPLIES, ['title' => '', 'body' => ''])->assertSessionHasErrors(['title', 'body']);

        $this->delete(self::REPLIES."/{$reply->id}")->assertRedirect(self::REPLIES);
        $this->assertSame(0, CannedReply::count());
    }

    public function test_staff_without_ticket_access_cannot_manage_them(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Custom, 'permissions' => ['manage-billing']]));

        $this->get(self::REPLIES)->assertForbidden();
        $this->post(self::REPLIES, ['title' => 'x', 'body' => 'y'])->assertForbidden();
    }

    public function test_ticket_page_offers_replies_filled_in_for_that_ticket(): void
    {
        $staff = User::factory()->create(['name' => 'Sam Staff', 'role' => StaffRole::Support]);
        $client = Client::factory()->create(['first_name' => 'Jane']);
        $department = Department::create(['name' => 'Technical', 'active' => true]);
        $ticket = Ticket::create(['client_id' => $client->id, 'department_id' => $department->id, 'subject' => 'Down', 'status' => 'open', 'priority' => 'medium']);
        CannedReply::create(['title' => 'Looking into it', 'body' => 'Hi {client_first_name}, {staff_name} here about #{ticket_id} {unknown}.']);

        $this->actingAs($staff)->get("/admin/tickets/{$ticket->id}")
            ->assertOk()
            ->assertSee('Looking into it')
            ->assertSee("Hi Jane, Sam Staff here about #{$ticket->id} {unknown}.");
    }
}
