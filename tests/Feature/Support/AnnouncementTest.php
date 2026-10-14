<?php

namespace Tests\Feature\Support;

use App\Models\Announcement;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/admin/announcements';

    private const LAUNCH = 'New data center in Dallas';

    private const SCHEDULED = 'Coming soon';

    public function test_staff_publish_announcements(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->post(self::ADMIN, ['title' => self::LAUNCH, 'body' => 'We have **opened** Dallas.', 'published_at' => '2026-10-01 09:00', 'published' => '1'])->assertRedirect(self::ADMIN);
        $announcement = Announcement::sole();
        $this->get(self::ADMIN)->assertOk()->assertSee(self::LAUNCH);

        $this->put(self::ADMIN."/{$announcement->id}", ['title' => 'Edited', 'body' => 'Text', 'published_at' => '2026-10-02 09:00'])->assertRedirect();
        $this->assertFalse($announcement->fresh()->published);

        $this->delete(self::ADMIN."/{$announcement->id}")->assertRedirect();
        $this->assertModelMissing($announcement);
    }

    public function test_clients_see_published_announcements_only(): void
    {
        $this->travelTo('2026-10-14 12:00');
        $live = Announcement::create(['title' => self::LAUNCH, 'body' => 'We have **opened** Dallas.', 'published_at' => now()->subDay()]);
        Announcement::create(['title' => self::SCHEDULED, 'body' => 'Later', 'published_at' => now()->addDay()]);
        $draft = Announcement::create(['title' => 'Draft news', 'body' => 'Not yet', 'published' => false, 'published_at' => now()->subDay()]);

        $this->get('/announcements')->assertOk()->assertSee(self::LAUNCH)->assertDontSee(self::SCHEDULED)->assertDontSee('Draft news');
        $this->get("/announcements/{$live->id}")->assertOk()->assertSee('<strong>opened</strong>', false);
        $this->get("/announcements/{$draft->id}")->assertNotFound();
        $this->get('/announcements/rss')->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')->assertSee(self::LAUNCH);

        $this->actingAs(Client::factory()->create(), 'client');
        $this->get('/portal')->assertOk()->assertSee(self::LAUNCH)->assertDontSee(self::SCHEDULED);
    }
}
