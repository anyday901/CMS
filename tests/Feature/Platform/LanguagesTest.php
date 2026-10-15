<?php

namespace Tests\Feature\Platform;

use App\Mail\ClientMailer;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LanguagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::save(['help.languages' => ['de', 'fr'], 'billing.company.name' => 'Example Hosting']);
    }

    public function test_visitors_pick_a_language_and_the_portal_is_shown_in_it(): void
    {
        $this->get(route('kb.index'))->assertOk()->assertSee('Knowledge base')->assertSee('Deutsch');

        $this->from(route('kb.index'))->post('/language', ['language' => 'de'])->assertRedirect(route('kb.index'));
        $this->get(route('kb.index'))->assertOk()->assertSee('Wissensdatenbank')->assertSee('<html lang="de"', false);

        $this->post('/language', ['language' => 'xx'])->assertSessionHasErrors('language');
    }

    public function test_the_browser_language_is_used_until_one_is_picked(): void
    {
        $this->get(route('kb.index'), ['Accept-Language' => 'fr-FR,fr;q=0.9'])->assertSee('<html lang="fr"', false);
    }

    public function test_a_signed_in_client_keeps_the_language_on_their_account_and_gets_emails_in_it(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($client, 'client')->post('/language', ['language' => 'de'])->assertRedirect();
        $this->assertSame('de', $client->fresh()->language);

        app(ClientMailer::class)->send($client->fresh(), 'password_reset', ['reset_url' => 'https://example.com/reset', 'expire_minutes' => 60]);
        $log = EmailLog::sole();
        $this->assertSame('Legen Sie Ihr Passwort für Example Hosting fest', $log->subject);
        $this->assertSame('en', app()->getLocale());

        // Going back to the main language clears it.
        $this->post('/language', ['language' => 'en']);
        $this->assertNull($client->fresh()->language);
    }

    public function test_staff_wording_for_a_language_wins_over_the_shipped_translation(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['language' => 'de']);

        $this->get('/admin/email-templates/password_reset?locale=de')->assertOk()->assertSee('Legen Sie Ihr Passwort');
        $this->put('/admin/email-templates/password_reset', ['locale' => 'de', 'subject' => 'Neues Passwort für {company_name}', 'body' => 'Hallo {client_first_name}'])
            ->assertRedirect('/admin/email-templates/password_reset?locale=de');

        app(ClientMailer::class)->send($client, 'password_reset', ['reset_url' => 'https://example.com/reset', 'expire_minutes' => 60]);
        $this->assertSame('Neues Passwort für Example Hosting', EmailLog::sole()->subject);
    }

    public function test_a_language_without_a_translation_falls_back_to_the_main_one(): void
    {
        Settings::save(['help.languages' => ['nl']]);
        $client = Client::factory()->create(['language' => 'nl']);

        app(ClientMailer::class)->send($client, 'password_reset', ['reset_url' => 'https://example.com/reset', 'expire_minutes' => 60]);
        $this->assertStringStartsWith('Set', EmailLog::sole()->subject);
    }

    public function test_staff_change_portal_text_in_any_language(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/settings/languages')->assertOk()->assertSee('German');
        $this->get('/admin/settings/languages/de?q=Knowledge')->assertOk()->assertSee('Wissensdatenbank');

        $this->put('/admin/settings/languages/de', ['rows' => [
            ['key' => 'Knowledge base', 'value' => 'Hilfe-Center'],
            ['key' => 'Not a portal string', 'value' => 'Ignored'],
        ]])->assertSessionHas('status', 'Saved 1 change(s).');
        $this->assertDatabaseCount('translation_overrides', 1);

        $this->post('/language', ['language' => 'de']);
        $this->get(route('kb.index'))->assertSee('<title>Hilfe-Center', false)->assertSee('Wissensdatenbank durchsuchen');

        $this->put('/admin/settings/languages/de', ['rows' => [['key' => 'Knowledge base', 'value' => '']]])->assertSessionHas('status', 'Saved 1 change(s).');
        $this->get(route('kb.index'))->assertSee('<title>Wissensdatenbank', false);
    }

    public function test_every_shipped_translation_is_complete(): void
    {
        $this->artisan('lang:check')->assertSuccessful();
    }
}
