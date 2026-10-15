<?php

namespace Tests\Feature\Security;

use App\Auth\Totp;
use App\Models\Client;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const WRONG_CODE = '000000';

    private const SECURITY = '/portal/security';

    private const HOME = '/portal';

    private const ADMIN_ACCOUNT = '/admin/account';

    private const PASSWORD = 'correct-horse-battery';

    private const PORTAL_LOGIN = '/portal/login';

    private const PORTAL_CHALLENGE = '/portal/two-factor';

    private const ADMIN_LOGIN = '/admin/login';

    private const ADMIN_CHALLENGE = '/admin/two-factor';

    /** Turns two-factor on the way a person does, and returns the secret. */
    private function enable(string $prefix): string
    {
        $this->post("{$prefix}/two-factor")->assertRedirect();
        $secret = session('two_factor_setup');
        $this->assertNotNull($secret);

        $this->post("{$prefix}/two-factor/confirm", ['code' => self::WRONG_CODE])->assertSessionHasErrors('code');
        $this->post("{$prefix}/two-factor/confirm", ['code' => Totp::code($secret, Totp::step() - 1)])
            ->assertSessionHas('recovery_codes');

        return $secret;
    }

    private function twoFactorClient(): array
    {
        $client = Client::factory()->create(['password' => self::PASSWORD]);
        $this->actingAs($client, 'client');
        $this->get(self::SECURITY)->assertOk()->assertSee('Set up two-factor');
        $secret = $this->enable(self::SECURITY);
        $this->get(self::SECURITY)->assertSee('Turned on');
        auth('client')->logout();
        $this->flushSession();

        return [$client->fresh(), $secret];
    }

    public function test_client_turns_on_two_factor_and_logs_in_with_a_code(): void
    {
        [$client, $secret] = $this->twoFactorClient();
        $this->assertTrue($client->hasTwoFactor());
        $this->assertSame(8, $client->recoveryCodesLeft());

        // The password alone doesn't sign in.
        $this->post(self::PORTAL_LOGIN, ['email' => $client->email, 'password' => self::PASSWORD])->assertRedirect(self::PORTAL_CHALLENGE);
        $this->assertGuest('client');
        $this->get(self::PORTAL_CHALLENGE)->assertOk()->assertSee('Enter your code');

        $this->post(self::PORTAL_CHALLENGE, ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest('client');
        $this->post(self::PORTAL_CHALLENGE, ['code' => Totp::code($secret, Totp::step())])->assertRedirect(self::HOME);
        $this->assertAuthenticatedAs($client, 'client');

        $this->assertSame([LoginAttempt::TWO_FACTOR_FAILED, LoginAttempt::SUCCESS], LoginAttempt::orderBy('id')->pluck('outcome')->all());
    }

    public function test_a_code_works_once_and_recovery_codes_are_used_up(): void
    {
        [$client, $secret] = $this->twoFactorClient();
        $code = Totp::code($secret, Totp::step());
        $this->post(self::PORTAL_LOGIN, ['email' => $client->email, 'password' => self::PASSWORD]);
        $this->post(self::PORTAL_CHALLENGE, ['code' => $code])->assertRedirect(self::HOME);
        $this->post('/portal/logout');

        // The same code, replayed, is refused.
        $this->post(self::PORTAL_LOGIN, ['email' => $client->email, 'password' => self::PASSWORD]);
        $this->post(self::PORTAL_CHALLENGE, ['code' => $code])->assertSessionHasErrors('code');

        $recovery = $client->fresh()->two_factor_recovery_codes[0];
        $this->post(self::PORTAL_CHALLENGE, ['code' => strtoupper($recovery)])->assertRedirect(self::HOME);
        $this->assertSame(7, $client->fresh()->recoveryCodesLeft());
        $this->post('/portal/logout');

        $this->post(self::PORTAL_LOGIN, ['email' => $client->email, 'password' => self::PASSWORD]);
        $this->post(self::PORTAL_CHALLENGE, ['code' => $recovery])->assertSessionHasErrors('code');
    }

    public function test_too_many_wrong_codes_send_you_back_to_the_password(): void
    {
        [$client] = $this->twoFactorClient();
        $this->post(self::PORTAL_LOGIN, ['email' => $client->email, 'password' => self::PASSWORD]);

        for ($i = 1; $i < 5; $i++) {
            $this->post(self::PORTAL_CHALLENGE, ['code' => self::WRONG_CODE])->assertSessionHasErrors('code');
        }
        $this->post(self::PORTAL_CHALLENGE, ['code' => self::WRONG_CODE])->assertRedirect(self::PORTAL_LOGIN);
        $this->get(self::PORTAL_CHALLENGE)->assertRedirect(self::PORTAL_LOGIN);
    }

    public function test_turning_off_and_new_recovery_codes_take_the_password(): void
    {
        [$client] = $this->twoFactorClient();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAs($client, 'client');
        $old = $client->two_factor_recovery_codes;

        $this->post('/portal/security/two-factor/recovery-codes', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post('/portal/security/two-factor/recovery-codes', ['current_password' => self::PASSWORD])->assertSessionHas('recovery_codes');
        $this->assertNotEquals($old, $client->fresh()->two_factor_recovery_codes);

        $this->delete('/portal/security/two-factor', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertTrue($client->fresh()->hasTwoFactor());
        $this->delete('/portal/security/two-factor', ['current_password' => self::PASSWORD]);
        $this->assertFalse($client->fresh()->hasTwoFactor());
        $this->assertNull($client->fresh()->getRawOriginal('two_factor_secret'));
    }

    public function test_the_secret_is_stored_encrypted(): void
    {
        [$client, $secret] = $this->twoFactorClient();

        $this->assertNotSame($secret, $client->getRawOriginal('two_factor_secret'));
        $this->assertArrayNotHasKey('two_factor_secret', $client->toArray());
    }

    public function test_staff_use_two_factor_and_admins_can_turn_it_off_for_them(): void
    {
        $staff = User::factory()->create(['password' => self::PASSWORD, 'role' => 'support']);
        $this->actingAs($staff);
        $this->get(self::ADMIN_ACCOUNT)->assertOk()->assertSee('Set up two-factor');
        $secret = $this->enable(self::ADMIN_ACCOUNT);
        $this->post('/admin/logout');

        $this->post(self::ADMIN_LOGIN, ['email' => $staff->email, 'password' => self::PASSWORD])->assertRedirect(self::ADMIN_CHALLENGE);
        $this->assertGuest('web');
        $this->post(self::ADMIN_CHALLENGE, ['code' => Totp::code($secret, Totp::step())])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($staff, 'web');
        $this->post('/admin/logout');

        // Support staff can't reset someone's two-factor; an admin can.
        $this->actingAs($staff)->delete("/admin/staff/{$staff->id}/two-factor")->assertForbidden();
        $this->actingAs(User::factory()->create())->delete("/admin/staff/{$staff->id}/two-factor")->assertRedirect();
        $this->assertFalse($staff->fresh()->hasTwoFactor());
    }

    public function test_staff_can_be_required_to_set_up_two_factor(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        // An admin without it can't make it required, so they don't lock themselves out.
        $this->put('/admin/settings/security', $this->securitySettings(['staff_two_factor' => '1']))->assertSessionHasErrors('staff_two_factor');
        $this->enable(self::ADMIN_ACCOUNT);
        $this->put('/admin/settings/security', $this->securitySettings(['staff_two_factor' => '1']))->assertSessionHasNoErrors();

        $other = User::factory()->create(['role' => 'billing']);
        $this->actingAs($other);
        $this->get('/admin/clients')->assertRedirect(self::ADMIN_ACCOUNT);
        $this->get(self::ADMIN_ACCOUNT)->assertOk();
        $this->enable(self::ADMIN_ACCOUNT);
        $this->get('/admin/clients')->assertOk();
    }

    public function test_staff_turn_off_a_clients_two_factor(): void
    {
        [$client] = $this->twoFactorClient();
        $this->actingAs(User::factory()->create(), 'web');

        $this->get("/admin/clients/{$client->id}")->assertSee('Two-factor authentication is <strong>on</strong>', false);
        $this->delete("/admin/clients/{$client->id}/two-factor")->assertRedirect();
        $this->assertFalse($client->fresh()->hasTwoFactor());
    }

    private function securitySettings(array $overrides = []): array
    {
        return $overrides + [
            'lockout_attempts' => 5, 'lockout_minutes' => 15, 'ban_after_failures' => 20, 'ban_hours' => 24,
            'login_history_days' => 90, 'staff_two_factor' => '0',
        ];
    }
}
