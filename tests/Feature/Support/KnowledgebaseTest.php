<?php

namespace Tests\Feature\Support;

use App\Enums\StaffRole;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgebaseTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_KB = '/admin/knowledgebase';

    private const DNS_TITLE = 'Pointing your domain at us';

    private function article(KbCategory $category, array $attributes = []): KbArticle
    {
        return KbArticle::create($attributes + [
            'kb_category_id' => $category->id,
            'title' => self::DNS_TITLE,
            'body' => "Set your **nameservers** to ns1.example.com.\n\n<script>alert(1)</script>",
            'tags' => 'dns, nameservers',
            'published' => true,
        ]);
    }

    public function test_staff_manage_categories_and_articles(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');

        $this->post('/admin/kb-categories', ['name' => 'Domains'])->assertRedirect(self::ADMIN_KB);
        $domains = KbCategory::sole();
        $this->post('/admin/kb-categories', ['name' => 'DNS', 'parent_id' => $domains->id])->assertRedirect();
        $dns = KbCategory::where('name', 'DNS')->sole();
        // Categories go one level deep.
        $this->post('/admin/kb-categories', ['name' => 'Deeper', 'parent_id' => $dns->id])->assertSessionHasErrors('parent_id');

        $this->post('/admin/kb-articles', ['kb_category_id' => $dns->id, 'title' => self::DNS_TITLE, 'body' => 'Set your nameservers.', 'published' => '1'])->assertRedirect();
        $article = KbArticle::sole();
        $this->assertTrue($article->published);
        $this->get(self::ADMIN_KB)->assertOk()->assertSee(self::DNS_TITLE)->assertSee('Domains');
        $this->get("/admin/kb-articles/{$article->id}/edit")->assertOk();

        $this->put("/admin/kb-articles/{$article->id}", ['kb_category_id' => $domains->id, 'title' => 'Renamed', 'body' => 'New text'])->assertRedirect();
        $this->assertSame(['Renamed', false, $domains->id], [$article->fresh()->title, $article->fresh()->published, $article->fresh()->kb_category_id]);

        // A category with articles can't be deleted.
        $this->delete("/admin/kb-categories/{$domains->id}")->assertSessionHasErrors('category');
        $this->delete("/admin/kb-articles/{$article->id}")->assertRedirect(self::ADMIN_KB);
        $this->delete("/admin/kb-categories/{$dns->id}")->assertRedirect();
        $this->assertModelMissing($dns);

        $this->actingAs(User::factory()->create(['role' => StaffRole::Custom, 'permissions' => ['manage-clients']]), 'web');
        $this->get(self::ADMIN_KB)->assertForbidden();
    }

    public function test_anyone_can_browse_search_and_read_articles(): void
    {
        $category = KbCategory::create(['name' => 'Domains', 'description' => 'Registering and pointing domains']);
        $article = $this->article($category);
        $this->article($category, ['title' => 'Draft article', 'published' => false]);
        $hidden = KbCategory::create(['name' => 'Internal', 'visible' => false]);
        $secret = $this->article($hidden, ['title' => 'Staff only']);

        $this->get('/knowledgebase')->assertOk()->assertSee('Domains')->assertDontSee('Internal');
        $this->get("/knowledgebase/category/{$category->id}")->assertOk()->assertSee(self::DNS_TITLE)->assertDontSee('Draft article');
        $this->get("/knowledgebase/category/{$hidden->id}")->assertNotFound();
        $this->get("/knowledgebase/{$secret->id}")->assertNotFound();

        $page = $this->get("/knowledgebase/{$article->id}")->assertOk();
        $page->assertSee('<strong>nameservers</strong>', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get("/knowledgebase/{$article->id}");
        $this->assertSame(1, $article->fresh()->views);

        $this->get('/knowledgebase?q=nameservers')->assertOk()->assertSee(self::DNS_TITLE)->assertDontSee('Staff only');
        $this->get('/knowledgebase?q=dns domain')->assertSee(self::DNS_TITLE);
        $this->get('/knowledgebase?q=billing')->assertSee('Nothing matched');
    }

    public function test_readers_vote_once(): void
    {
        $article = $this->article(KbCategory::create(['name' => 'Domains']));

        $this->post("/knowledgebase/{$article->id}/vote", ['helpful' => '1'])->assertRedirect("/knowledgebase/{$article->id}");
        $this->post("/knowledgebase/{$article->id}/vote", ['helpful' => '0']);

        $this->assertSame([1, 0], [$article->fresh()->helpful, $article->fresh()->not_helpful]);
        $this->get("/knowledgebase/{$article->id}")->assertSee('Thanks for your feedback');
    }
}
