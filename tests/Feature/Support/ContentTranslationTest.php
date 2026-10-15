<?php

namespace Tests\Feature\Support;

use App\Models\Announcement;
use App\Models\ContentTranslation;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\User;
use App\Support\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentTranslationTest extends TestCase
{
    use RefreshDatabase;

    private const FRENCH = 'fr-FR,fr;q=0.9,en;q=0.5';

    private const FRENCH_TITLE = 'Réinitialiser votre mot de passe';

    private KbArticle $article;

    protected function setUp(): void
    {
        parent::setUp();
        config(['help.main_language' => 'en', 'help.languages' => ['fr', 'de']]);
        $category = KbCategory::create(['name' => 'Accounts', 'visible' => true]);
        $this->article = KbArticle::create(['kb_category_id' => $category->id, 'title' => 'Reset your password', 'body' => 'Click **Forgot password**.', 'tags' => 'login', 'published' => true]);
    }

    private function translate(): void
    {
        $this->article->translations()->create(['locale' => 'fr', 'title' => self::FRENCH_TITLE, 'body' => 'Cliquez sur **Mot de passe oublié**.', 'tags' => 'connexion']);
    }

    public function test_staff_add_change_and_remove_a_translation(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->get(route('admin.kb-articles.edit', $this->article))->assertOk()->assertSee('French')->assertSee('German')->assertSee('not translated yet');

        $this->put(route('admin.translations.update', ['kb-articles', $this->article->id, 'fr']), ['locale' => 'fr', 'title' => self::FRENCH_TITLE, 'body' => 'Texte', 'tags' => 'connexion'])
            ->assertRedirect(route('admin.kb-articles.edit', $this->article).'#translation-fr');
        $this->put(route('admin.translations.update', ['kb-articles', $this->article->id, 'fr']), ['locale' => 'fr', 'title' => self::FRENCH_TITLE, 'body' => 'Nouveau texte']);

        $translation = ContentTranslation::sole();
        $this->assertSame(['fr', 'Nouveau texte', null], [$translation->locale, $translation->body, $translation->tags]);
        // The article itself, as staff edit it, stays in the main language.
        $this->assertSame('Reset your password', $this->article->fresh()->title);

        // Only languages offered under Settings, and only articles and announcements.
        $this->put(route('admin.translations.update', ['kb-articles', $this->article->id, 'es']), ['title' => 'x', 'body' => 'y'])->assertNotFound();
        $this->put('/admin/translations/products/1/fr', ['title' => 'x', 'body' => 'y'])->assertNotFound();

        $this->delete(route('admin.translations.destroy', ['kb-articles', $this->article->id, 'fr']))->assertRedirect();
        $this->assertSame(0, ContentTranslation::count());
    }

    public function test_visitors_see_the_translation_their_browser_asks_for_or_the_one_they_pick(): void
    {
        $this->translate();

        $this->get(route('kb.article', $this->article), ['Accept-Language' => self::FRENCH])
            ->assertOk()->assertSee(self::FRENCH_TITLE)->assertSee('lang="fr"', false)->assertSee('Mot de passe oublié');
        $this->get(route('kb.article', $this->article), ['Accept-Language' => 'en-US'])->assertOk()->assertSee('Reset your password')->assertDontSee(self::FRENCH_TITLE);

        // German is offered but this article isn't translated, so it shows in English.
        $this->post(route('help.language'), ['language' => 'de'])->assertRedirect();
        $this->get(route('kb.article', $this->article), ['Accept-Language' => self::FRENCH])->assertOk()->assertSee('Reset your password')->assertSee('lang="en"', false);

        $this->post(route('help.language'), ['language' => 'fr']);
        $this->get(route('kb.index'))->assertOk()->assertSee(self::FRENCH_TITLE)->assertSee('<option value="fr" lang="fr" selected>French</option>', false);
        $this->post(route('help.language'), ['language' => 'xx'])->assertSessionHasErrors('language');
    }

    public function test_search_matches_the_translation_in_the_visitors_language(): void
    {
        $this->translate();

        $this->get(route('kb.index', ['q' => 'oublié']), ['Accept-Language' => self::FRENCH])->assertOk()->assertSee('1 result');
        $this->get(route('kb.index', ['q' => 'oublié']), ['Accept-Language' => 'en'])->assertOk()->assertSee('0 results');
        $this->get(route('kb.index', ['q' => 'password']), ['Accept-Language' => self::FRENCH])->assertOk()->assertSee('1 result');
        $this->assertSame(self::FRENCH_TITLE, KbArticle::suggest('connexion')->first()?->title ?? 'none', 'Suggestions use the translation too.');
    }

    public function test_announcements_are_translated_and_deleting_content_removes_its_translations(): void
    {
        $announcement = Announcement::create(['title' => 'New data centre', 'body' => 'We moved.', 'published' => true, 'published_at' => now()->subDay()]);
        $announcement->translations()->create(['locale' => 'de', 'title' => 'Neues Rechenzentrum', 'body' => 'Wir sind umgezogen.']);

        $this->get(route('announcements.index'), ['Accept-Language' => 'de-DE'])->assertOk()->assertSee('Neues Rechenzentrum');
        $this->get(route('announcements.show', $announcement), ['Accept-Language' => 'de'])->assertOk()->assertSee('Wir sind umgezogen.');
        $this->get(route('announcements.rss'), ['Accept-Language' => 'de'])->assertOk()->assertSee('Neues Rechenzentrum');

        $announcement->delete();
        $this->assertSame(0, ContentTranslation::count());
    }

    public function test_staff_set_the_languages_under_settings(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $form = ['late_fee_type' => 'fixed'];

        $this->put(route('admin.settings.update'), $form + ['main_language' => 'EN', 'languages' => 'pt_br, fr, en, fr'])->assertRedirect();
        $this->assertSame(['en', ['pt-BR', 'fr']], [Language::main(), Language::others()]);

        $this->put(route('admin.settings.update'), $form + ['main_language' => 'en', 'languages' => 'fr, klingon!'])->assertSessionHasErrors('languages');
        $this->assertSame(['pt-BR', 'fr'], Language::others());
    }
}
