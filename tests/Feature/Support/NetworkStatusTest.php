<?php

namespace Tests\Feature\Support;

use App\Models\Client;
use App\Models\NetworkIssue;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkStatusTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/admin/network-issues';

    private const OUTAGE = 'web1 is not responding';

    private const PAGE = '/network-status';

    public function test_staff_add_update_and_resolve_issues(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $server = Server::factory()->create(['name' => 'web1']);

        $this->post(self::ADMIN, [
            'title' => self::OUTAGE, 'description' => 'Looking into it.', 'server_id' => $server->id,
            'priority' => 'critical', 'status' => 'investigating', 'starts_at' => '2026-10-14 10:00',
        ])->assertRedirect(self::ADMIN);
        $issue = NetworkIssue::sole();
        $this->get(self::ADMIN)->assertOk()->assertSee(self::OUTAGE)->assertSee('web1');

        $this->post(self::ADMIN, ['title' => 'Bad', 'description' => 'x', 'priority' => 'urgent', 'status' => 'open', 'starts_at' => 'soon'])
            ->assertSessionHasErrors(['priority', 'status', 'starts_at']);

        $this->post(self::ADMIN."/{$issue->id}/resolve")->assertRedirect();
        $this->assertSame(NetworkIssue::RESOLVED, $issue->fresh()->status);
        $this->assertNotNull($issue->fresh()->ends_at);
    }

    public function test_the_public_page_lists_current_planned_and_resolved_issues_and_chosen_servers(): void
    {
        $this->travelTo('2026-10-14 12:00');
        NetworkIssue::create(['title' => self::OUTAGE, 'description' => 'Looking into it.', 'affecting' => 'Shared hosting', 'priority' => 'high', 'status' => 'outage', 'starts_at' => now()->subHour()]);
        NetworkIssue::create(['title' => 'Kernel updates', 'description' => 'Reboots.', 'priority' => 'low', 'status' => NetworkIssue::SCHEDULED, 'starts_at' => now()->addDays(2)]);
        NetworkIssue::create(['title' => 'Email delays', 'description' => 'Fixed.', 'priority' => 'medium', 'status' => NetworkIssue::RESOLVED, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        Server::factory()->create(['name' => 'shown-server', 'show_status' => true, 'status' => Server::UP, 'status_checked_at' => now()]);
        Server::factory()->create(['name' => 'quiet-server', 'show_status' => false]);

        $page = $this->get(self::PAGE)->assertOk();
        $page->assertSeeInOrder(['Current issues', self::OUTAGE, 'Servers', 'shown-server', 'Planned maintenance', 'Kernel updates', 'Resolved', 'Email delays']);
        $page->assertDontSee('quiet-server');

        // An issue dated ahead isn't current yet, whatever its status.
        NetworkIssue::create(['title' => 'Future outage', 'description' => 'x', 'priority' => 'high', 'status' => 'investigating', 'starts_at' => now()->addHour()]);
        $this->get(self::PAGE)->assertDontSee('Future outage');

        NetworkIssue::query()->update(['status' => NetworkIssue::RESOLVED]);
        $this->get(self::PAGE)->assertSee('All systems are working normally');
    }

    public function test_clients_with_a_service_on_an_affected_server_see_a_notice(): void
    {
        $affected = Server::factory()->create(['name' => 'web1']);
        $other = Server::factory()->create(['name' => 'web2']);
        $client = Client::factory()->create();
        Service::factory()->for($client)->create(['server_id' => $other->id]);
        NetworkIssue::create(['title' => self::OUTAGE, 'description' => 'x', 'server_id' => $affected->id, 'priority' => 'high', 'status' => 'outage', 'starts_at' => now()->subMinute()]);

        $this->actingAs($client, 'client');
        $this->get('/portal')->assertOk()->assertDontSee(self::OUTAGE);

        Service::factory()->for($client)->create(['server_id' => $affected->id]);
        $this->get('/portal')->assertSee(self::OUTAGE)->assertSee('affects web1');
    }
}
