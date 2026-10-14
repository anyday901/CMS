<?php

namespace Tests\Feature\Support;

use App\Models\Client;
use App\Models\Department;
use App\Models\KbArticle;
use App\Models\KbCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KbSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private const SUGGEST = '/portal/support/suggest';

    protected function setUp(): void
    {
        parent::setUp();
        $category = KbCategory::create(['name' => 'Email']);
        $hidden = KbCategory::create(['name' => 'Internal', 'visible' => false]);
        KbArticle::create(['kb_category_id' => $category->id, 'title' => 'Set up email on your phone', 'body' => 'Use IMAP on port 993.', 'tags' => 'iphone, android']);
        KbArticle::create(['kb_category_id' => $category->id, 'title' => 'Change your password', 'body' => 'Open settings and pick a new **password** for email.']);
        KbArticle::create(['kb_category_id' => $category->id, 'title' => 'Email draft', 'body' => 'Not ready', 'published' => false]);
        KbArticle::create(['kb_category_id' => $hidden->id, 'title' => 'Email server notes', 'body' => 'Staff only']);
    }

    public function test_clients_get_articles_matching_what_they_type(): void
    {
        $this->actingAs(Client::factory()->create(), 'client');

        $response = $this->getJson(self::SUGGEST.'?subject='.urlencode("Can't get email working on my iPhone"))->assertOk();
        $this->assertSame(['Set up email on your phone', 'Change your password'], array_column($response->json('articles'), 'title'));
        $this->assertStringContainsString('/knowledgebase/', $response->json('articles.0.url'));

        $this->getJson(self::SUGGEST.'?subject=the+and+for')->assertExactJson(['articles' => []]);
        $this->getJson(self::SUGGEST.'?subject=hi&body='.urlencode('I forgot my password'))->assertJsonPath('articles.0.title', 'Change your password');
    }

    public function test_the_new_ticket_form_asks_for_suggestions_and_guests_cannot(): void
    {
        $department = Department::create(['name' => 'Technical', 'active' => true]);

        $this->getJson(self::SUGGEST.'?subject=email')->assertUnauthorized();
        $this->actingAs(Client::factory()->create(), 'client')
            ->get(route('portal.tickets.create', ['department' => $department->id]))
            ->assertOk()
            ->assertSee('These articles might answer your question')
            ->assertSee('support\\/suggest', false);
    }
}
