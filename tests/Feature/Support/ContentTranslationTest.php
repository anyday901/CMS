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

    private const TITLE = 'Reset your password';

    private const GERMAN_TITLE = 'Neues Rechenzentrum';

    private const FRENCH_SUBCATEGORY = 'Connexion en deux étapes';

    private KbArticle $article;

    protected function setUp(): void
    {
        parent::setUp();
        config(['help.main_language' => 'en', 'help.languages' => ['fr', 'de']]);
        $category = KbCategory::create(['name' => 'Accounts', 'visible' => true]);
        $this->article = KbArticle::create(['kb_category_id' => $category->id, 'title' => self::TITLE, 'body' => 'Click **Forgot password**.', 'tags' => 'login', 'published' => true]);
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
        $this->assertSame(self::TITLE, $this->article->fresh()->title);

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
        $this->get(route('kb.article', $this->article), ['Accept-Language' => 'en-US'])->assertOk()->assertSee(self::TITLE)->assertDontSee(self::FRENCH_TITLE);

        // German is offered but this article isn't translated, so it shows in English.
        $this->post(route('help.language'), ['language' => 'de'])->assertRedirect();
        $this->get(route('kb.article', $this->article), ['Accept-Language' => self::FRENCH])->assertOk()->assertSee(self::TITLE)->assertSee('lang="en"', false);

        $this->post(route('help.language'), ['language' => 'fr']);
        $this->get(route('kb.index'))->assertOk()->assertSee(self::FRENCH_TITLE)->assertSee('<option value="fr" lang="fr" selected>Français</option>', false);
        $this->post(route('help.language'), ['language' => 'xx'])->assertSessionHasErrors('language');
    }

    public function test_search_matches_the_translation_in_the_visitors_language(): void
    {
        $this->translate();

        $this->get(route('kb.index', ['q' => 'oublié']), ['Accept-Language' => self::FRENCH])->assertOk()->assertSee('1 résultat');
        $this->get(route('kb.index', ['q' => 'oublié']), ['Accept-Language' => 'en'])->assertOk()->assertSee('0 results');
        $this->get(route('kb.index', ['q' => 'password']), ['Accept-Language' => self::FRENCH])->assertOk()->assertSee('1 résultat');
        $this->assertSame(self::FRENCH_TITLE, KbArticle::suggest('connexion')->first()?->title ?? 'none', 'Suggestions use the translation too.');
    }

    public function test_announcements_are_translated_and_deleting_content_removes_its_translations(): void
    {
        $announcement = Announcement::create(['title' => 'New data centre', 'body' => 'We moved.', 'published' => true, 'published_at' => now()->subDay()]);
        $announcement->translations()->create(['locale' => 'de', 'title' => self::GERMAN_TITLE, 'body' => 'Wir sind umgezogen.']);

        $this->get(route('announcements.index'), ['Accept-Language' => 'de-DE'])->assertOk()->assertSee(self::GERMAN_TITLE);
        $this->get(route('announcements.show', $announcement), ['Accept-Language' => 'de'])->assertOk()->assertSee('Wir sind umgezogen.');
        $this->get(route('announcements.rss'), ['Accept-Language' => 'de'])->assertOk()->assertSee(self::GERMAN_TITLE);

        $announcement->delete();
        $this->assertSame(0, ContentTranslation::count());
    }

    public function test_category_names_and_descriptions_are_translated(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $category = $this->article->category;
        $category->update(['description' => 'Logins and passwords']);
        $child = KbCategory::create(['name' => 'Two-step login', 'parent_id' => $category->id, 'visible' => true]);
        $this->article->update(['kb_category_id' => $child->id]);

        $this->get(route('admin.kb-categories.edit', $category))->assertOk()->assertSee('French')->assertSee('not translated yet');
        $this->put(route('admin.translations.update', ['kb-categories', $category->id, 'fr']), ['title' => 'Comptes', 'body' => ''])
            ->assertRedirect(route('admin.kb-categories.edit', $category).'#translation-fr');
        $this->put(route('admin.translations.update', ['kb-categories', $child->id, 'fr']), ['title' => self::FRENCH_SUBCATEGORY, 'body' => 'Codes à usage unique']);
        $this->put(route('admin.translations.update', ['kb-categories', $category->id, 'fr']), ['title' => 'Comptes', 'body' => 'x', 'tags' => 'y'])->assertSessionHasErrors('tags');
        $this->assertNull($category->translations()->sole()->body);
        $this->assertSame('Accounts', $category->fresh()->name, 'Staff edit the main language.');

        $french = ['Accept-Language' => self::FRENCH];
        // A translation without a description shows the main one.
        $this->get(route('kb.index'), $french)->assertOk()->assertSee('Comptes')->assertSee('Logins and passwords')->assertSee(self::FRENCH_SUBCATEGORY);
        $this->get(route('kb.category', $child), $french)->assertOk()->assertSee('Comptes')->assertSee('Codes à usage unique')->assertSee('lang="fr"', false);
        $this->get(route('kb.article', $this->article), $french)->assertOk()->assertSee('Comptes')->assertSee(self::FRENCH_SUBCATEGORY);
        $this->get(route('kb.index', ['q' => 'password']), $french)->assertOk()->assertSee(self::FRENCH_SUBCATEGORY);
        $this->get(route('kb.index'), ['Accept-Language' => 'en'])->assertOk()->assertSee('Accounts')->assertDontSee('Comptes');

        $this->delete(route('admin.translations.destroy', ['kb-categories', $child->id, 'fr']))->assertRedirect();
        $this->get(route('kb.category', $child), $french)->assertOk()->assertSee('Two-step login');
        $this->article->delete();
        $child->delete();
        $category->delete();
        $this->assertSame(0, ContentTranslation::count(), 'Deleting a category removes its translations.');
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
