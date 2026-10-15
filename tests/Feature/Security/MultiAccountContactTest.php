<?php

namespace Tests\Feature\Security;

use App\Auth\ContactRememberCookie;
use App\Auth\Totp;
use App\Mail\LoggedEmail;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\EmailLog;
use App\Models\KnownDevice;
use App\Models\User;
use App\Security\NewDeviceAlert;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class MultiAccountContactTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'pat@example.com';

    private const PASSWORD = 'contact-password-1';

    private const LOGIN = '/portal/login';

    private const ACCOUNTS = '/portal/accounts';

    private const HOME = '/portal';

    private const BOLT = 'Bolt Inc';

    private const INVOICES = '/portal/invoices';

    private const LOGOUT = '/portal/logout';

    private function contact(Client $client, array $permissions = ['billing'], ?string $password = self::PASSWORD): ClientContact
    {
        $contact = $client->contacts()->create([
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::EMAIL,
            'portal_access' => true, 'permissions' => $permissions,
        ]);

        if ($password !== null && $contact->password === null) {
            $contact->forceFill(['password' => $password])->save();
        }

        return $contact;
    }

    /** Ends the session as if the browser was closed, keeping its cookies. */
    private function endSession(): void
    {
        $this->flushSession();
        Auth::guard('client')->forgetUser();
    }

    public function test_one_login_reaches_several_accounts_and_switches_between_them(): void
    {
        $acme = Client::factory()->create(['company' => 'Acme Ltd']);
        $bolt = Client::factory()->create(['company' => self::BOLT]);
        $first = $this->contact($acme, ['billing']);
        $second = $this->contact($bolt, ['support']);

        // The second account's contact uses the login the person already had.
        $this->assertSame($first->password, $second->fresh()->password);

        $this->post(self::LOGIN, ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertRedirect(self::ACCOUNTS);
        $this->get(self::ACCOUNTS)->assertOk()->assertSee('Acme Ltd')->assertSee(self::BOLT)->assertSee('invoices')->assertSee('support');

        $this->post("/portal/accounts/{$second->id}")->assertRedirect(self::HOME);
        $this->assertSame($bolt->id, Auth::guard('client')->id());
        $this->get(self::HOME)->assertOk()->assertSee(self::BOLT)->assertSee('Switch account', false);
        $this->get('/portal/support')->assertOk();
        $this->get(self::INVOICES)->assertRedirect(self::HOME);

        $this->post("/portal/accounts/{$first->id}")->assertRedirect(self::HOME);
        $this->assertSame($acme->id, Auth::guard('client')->id());
        $this->get(self::INVOICES)->assertOk();

        // Someone else's contact isn't one of this login's accounts.
        $stranger = Client::factory()->create()->contacts()->create(['first_name' => 'S', 'last_name' => 'T', 'email' => 'other@example.com', 'portal_access' => true]);
        $this->post("/portal/accounts/{$stranger->id}")->assertNotFound();

        // The account used last is where the next login lands.
        $this->post(self::LOGOUT);
        $this->post(self::LOGIN, ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        $this->assertSame($acme->id, Auth::guard('client')->id());
    }

    public function test_the_login_stays_the_same_on_every_account(): void
    {
        $first = $this->contact(Client::factory()->create());
        $second = $this->contact(Client::factory()->create());
        $this->post(self::LOGIN, ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $new = 'a-brand-new-password';
        $this->put('/portal/security/password', ['current_password' => self::PASSWORD, 'password' => $new, 'password_confirmation' => $new])->assertSessionHas('status');
        $this->assertSame($first->fresh()->password, $second->fresh()->password);

        $this->post('/portal/security/two-factor');
        $secret = session('two_factor_setup');
        $this->post('/portal/security/two-factor/confirm', ['code' => Totp::code($secret, Totp::step())])->assertSessionHas('recovery_codes');
        $this->assertTrue($second->fresh()->hasTwoFactor());
        $this->assertSame($secret, $second->fresh()->two_factor_secret);

        // Staff turning it off for one account turns it off for the login.
        $this->actingAs(User::factory()->create(), 'web');
        $this->delete("/admin/contacts/{$second->id}/two-factor")->assertRedirect();
        $this->assertFalse($first->fresh()->hasTwoFactor());
    }

    public function test_a_contact_given_access_to_another_account_is_told_instead_of_invited(): void
    {
        Mail::fake();
        $this->contact(Client::factory()->create());
        $bolt = Client::factory()->create(['company' => self::BOLT]);
        $this->actingAs($bolt, 'client');

        $this->post('/portal/account/contacts', [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::EMAIL, 'portal_access' => '1', 'permissions' => ['support'],
        ])->assertSessionHas('status', fn (string $status) => str_contains($status, 'already signs in to another account'));

        $this->assertNotNull($bolt->contacts()->sole()->password);
        $this->assertSame('contact_account_added', EmailLog::sole()->template);
        Mail::assertSent(LoggedEmail::class, fn (LoggedEmail $mail) => $mail->hasTo(self::EMAIL) && str_contains($mail->render(), 'Bolt Inc has given you access'));
        $this->get('/portal/account')->assertSee('same login on 1 other account');
    }

    public function test_changing_a_contacts_email_leaves_the_shared_login_behind(): void
    {
        Mail::fake();
        $first = $this->contact(Client::factory()->create());
        $bolt = Client::factory()->create();
        $second = $this->contact($bolt);
        $this->actingAs($bolt, 'client');

        $this->put("/portal/account/contacts/{$second->id}", [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'someone-else@example.com', 'portal_access' => '1', 'permissions' => ['billing'],
        ])->assertSessionHas('status', fn (string $status) => str_contains($status, 'a link to choose their password'));

        $this->assertNull($second->fresh()->password);
        $this->assertNotNull($first->fresh()->password);
        $this->assertSame('contact_invite', EmailLog::sole()->template);
    }

    public function test_an_invited_contact_on_two_accounts_sets_one_password_for_both(): void
    {
        $first = $this->contact(Client::factory()->create(), password: null);
        $second = $this->contact(Client::factory()->create(), password: null);
        $token = Password::broker('contacts')->createToken($first);

        $this->post('/portal/reset-password', ['token' => $token, 'email' => self::EMAIL, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertRedirect(self::LOGIN);

        $this->assertNotNull($first->fresh()->password);
        $this->assertSame($first->fresh()->password, $second->fresh()->password);
    }

    public function test_a_contact_who_loses_one_account_keeps_the_other(): void
    {
        $first = $this->contact(Client::factory()->create());
        $second = $this->contact(Client::factory()->create(['status' => 'closed']));

        // Closed accounts aren't offered.
        $this->post(self::LOGIN, ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertRedirect(self::HOME);
        $this->post("/portal/accounts/{$second->id}")->assertNotFound();

        $first->update(['portal_access' => false]);
        $this->get(self::HOME)->assertRedirect(self::LOGIN);
        $second->client->update(['status' => 'active']);
        $this->post(self::LOGIN, ['email' => self::EMAIL, 'password' => self::PASSWORD])->assertRedirect(self::HOME);
        $this->assertSame($second->client_id, Auth::guard('client')->id());
    }

    public function test_contacts_can_be_remembered_without_signing_in_the_account_holder(): void
    {
        $client = Client::factory()->create();
        $client->forceFill(['remember_token' => 'owner-remember-token'])->save();
        $contact = $this->contact($client);

        $response = $this->post(self::LOGIN, ['email' => self::EMAIL, 'password' => self::PASSWORD, 'remember' => '1']);
        $cookie = $response->getCookie(ContactRememberCookie::NAME)?->getValue();
        $this->assertNotNull($cookie);
        $this->assertNotNull($contact->fresh()->remember_token);
        $this->assertSame('owner-remember-token', $client->fresh()->remember_token);

        // The session ends; the cookie signs the contact back in, as the contact.
        $this->endSession();
        $this->withCookie(ContactRememberCookie::NAME, $cookie)->get(self::INVOICES)->assertOk()->assertSee('Log out Pat');

        // A forged or old cookie does nothing.
        $this->endSession();
        $this->withCookie(ContactRememberCookie::NAME, $contact->id.'|wrong|token')->get(self::HOME)->assertRedirect(self::LOGIN);

        // A new password ends it.
        $this->endSession();
        $contact->fresh()->forceFill(['password' => 'another-password-1'])->save();
        $this->withCookie(ContactRememberCookie::NAME, $cookie)->get(self::HOME)->assertRedirect(self::LOGIN);
    }

    public function test_logging_out_ends_a_remembered_contact_login(): void
    {
        $contact = $this->contact(Client::factory()->create());
        $cookie = $this->post(self::LOGIN, ['email' => self::EMAIL, 'password' => self::PASSWORD, 'remember' => '1'])
            ->getCookie(ContactRememberCookie::NAME)->getValue();

        $this->withCookie(ContactRememberCookie::NAME, $cookie)->post(self::LOGOUT);
        $this->endSession();
        $this->withCookie(ContactRememberCookie::NAME, $cookie)->get(self::HOME)->assertRedirect(self::LOGIN);
        $this->assertGuest('client');
        $this->assertNotNull($contact->fresh()->remember_token);
    }

    public function test_a_login_from_a_new_device_is_emailed_about(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['password' => self::PASSWORD]);
        $browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

        // The first device an email signs in from is just noted.
        $device = $this->withHeader('User-Agent', $browser)->post(self::LOGIN, ['email' => $client->email, 'password' => self::PASSWORD])
            ->getCookie(NewDeviceAlert::COOKIE)->getValue();
        $this->assertSame(1, KnownDevice::count());
        Mail::assertNothingSent();

        // The same browser again: nothing.
        $this->post(self::LOGOUT);
        $this->withCookie(NewDeviceAlert::COOKIE, $device)->post(self::LOGIN, ['email' => $client->email, 'password' => self::PASSWORD]);
        Mail::assertNothingSent();

        // Another browser: an email with when, what and where.
        $this->post(self::LOGOUT);
        $this->defaultCookies = [];
        $this->withHeader('User-Agent', $browser)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post(self::LOGIN, ['email' => $client->email, 'password' => self::PASSWORD]);
        $log = EmailLog::sole();
        $this->assertSame('new_device_login', $log->template);
        $this->assertStringContainsString('Chrome on Windows', $log->body);
        $this->assertStringContainsString('203.0.113.9', $log->body);
        $this->assertSame(2, KnownDevice::count());

        // Turned off under Security, a new device is still noted but not emailed.
        Settings::save(['security.new_device_emails' => false]);
        $this->post(self::LOGOUT);
        $this->defaultCookies = [];
        $this->post(self::LOGIN, ['email' => $client->email, 'password' => self::PASSWORD]);
        $this->assertSame(1, EmailLog::count());
        $this->assertSame(3, KnownDevice::count());
    }

    public function test_pruning_old_devices_keeps_the_last_one_so_a_new_device_is_still_emailed_about(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['password' => self::PASSWORD]);
        $email = strtolower($client->email);
        foreach ([3, 2] as $years) {
            KnownDevice::create(['email' => $email, 'token_hash' => hash('sha256', "old-{$years}"), 'last_used_at' => now()->subYears($years), 'created_at' => now()->subYears($years)]);
        }

        $this->artisan('model:prune', ['--model' => [KnownDevice::class]])->assertSuccessful();
        $this->assertSame([hash('sha256', 'old-2')], KnownDevice::pluck('token_hash')->all());

        $this->post(self::LOGIN, ['email' => $client->email, 'password' => self::PASSWORD]);
        $this->assertSame('new_device_login', EmailLog::sole()->template);
    }

    public function test_contacts_are_emailed_about_new_devices_too(): void
    {
        Mail::fake();
        $this->contact(Client::factory()->create());
        KnownDevice::create(['email' => self::EMAIL, 'token_hash' => hash('sha256', 'old'), 'last_used_at' => now(), 'created_at' => now()]);

        $this->post(self::LOGIN, ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        Mail::assertSent(LoggedEmail::class, fn (LoggedEmail $mail) => $mail->hasTo(self::EMAIL) && str_contains($mail->render(), 'Hello Pat'));
    }
}
