<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\Department;
use App\Models\User;
use App\Security\Captcha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CaptchaTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const PASSWORD = 'right-password-1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.captcha' => ['provider' => 'turnstile', 'site_key' => 'site-123', 'secret_key' => 'secret-456', 'forms' => ['login', 'password', 'contact']]]);
    }

    public function test_forms_show_the_widget_only_where_it_is_turned_on(): void
    {
        $this->get('/portal/login')->assertSee('challenges.cloudflare.com/turnstile/v0/api.js')->assertSee('data-sitekey="site-123"', false);
        $this->get('/portal/forgot-password')->assertSee('cf-turnstile');
        $this->get('/admin/login')->assertDontSee('cf-turnstile');

        config(['security.captcha.secret_key' => null]);
        $this->get('/portal/login')->assertDontSee('cf-turnstile');
    }

    public function test_logins_need_a_solved_captcha(): void
    {
        $client = Client::factory()->create(['password' => self::PASSWORD]);
        Http::fake([self::VERIFY => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->post('/portal/login', ['email' => $client->email, 'password' => self::PASSWORD])->assertSessionHasErrors('captcha');
        $this->post('/portal/login', ['email' => $client->email, 'password' => self::PASSWORD, 'cf-turnstile-response' => 'bad'])->assertSessionHasErrors('captcha');
        $this->assertGuest('client');

        $this->post('/portal/login', ['email' => $client->email, 'password' => self::PASSWORD, 'cf-turnstile-response' => 'good'])->assertRedirect('/portal');
        Http::assertSent(fn (Request $request) => $request['secret'] === 'secret-456' && $request['response'] === 'good');
    }

    public function test_the_contact_form_and_forgot_password_check_it_too(): void
    {
        Mail::fake();
        Http::fake([self::VERIFY => ['success' => false]]);
        config(['tickets.guest_tickets' => true]);
        $department = Department::create(['name' => 'Sales', 'active' => true]);

        $this->post('/portal/forgot-password', ['email' => 'a@example.com', 'cf-turnstile-response' => 'x'])->assertSessionHasErrors('captcha');
        $this->post(route('tickets.guest.store'), [
            'name' => 'Sam', 'email' => 'sam@example.com', 'department_id' => $department->id, 'priority' => 'medium',
            'subject' => 'Hi', 'body' => 'Hello', 'cf-turnstile-response' => 'x',
        ])->assertSessionHasErrors('captcha');
        Mail::assertNothingSent();
    }

    public function test_a_provider_that_cannot_be_reached_does_not_lock_everyone_out(): void
    {
        $client = Client::factory()->create(['password' => self::PASSWORD]);
        Http::fake([self::VERIFY => fn () => throw new ConnectionException('down')]);

        $this->post('/portal/login', ['email' => $client->email, 'password' => self::PASSWORD, 'cf-turnstile-response' => 'x'])->assertRedirect('/portal');
    }

    public function test_staff_set_up_the_captcha_and_the_secret_is_stored_encrypted(): void
    {
        config(['security.captcha' => ['provider' => null, 'site_key' => null, 'secret_key' => null, 'forms' => []]]);
        $this->actingAs(User::factory()->create(), 'web');
        $settings = ['lockout_attempts' => 5, 'lockout_minutes' => 15, 'ban_hours' => 24, 'login_history_days' => 90];

        $this->put('/admin/settings/security', $settings + ['captcha_provider' => 'hcaptcha', 'captcha_site_key' => 'site'])->assertSessionHasErrors('captcha_site_key');
        $this->put('/admin/settings/security', $settings + [
            'captcha_provider' => 'hcaptcha', 'captcha_site_key' => 'site', 'captcha_secret_key' => 'top-secret', 'captcha_forms' => ['register', 'contact'],
        ])->assertSessionHasNoErrors();

        $saved = DB::table('settings')->where('key', 'security.captcha.secret_key')->value('value');
        $this->assertNotSame('top-secret', $saved);
        $this->assertSame('top-secret', Crypt::decryptString($saved));
        $this->assertTrue(Captcha::enabledFor('register'));
        $this->assertFalse(Captcha::enabledFor('login'));

        // Leaving the secret blank keeps the saved one.
        $this->put('/admin/settings/security', $settings + ['captcha_provider' => 'hcaptcha', 'captcha_site_key' => 'site2', 'captcha_forms' => ['login']])->assertSessionHasNoErrors();
        $this->assertSame('top-secret', config('security.captcha.secret_key'));
        $this->get('/admin/settings/security')->assertSee('Saved. Leave blank to keep it')->assertDontSee('top-secret');
    }
}
