<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\IpBan;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginProtectionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'right-password-1';

    private const LOGIN = '/portal/login';

    private const SECURITY = '/admin/settings/security';

    protected function setUp(): void
    {
        parent::setUp();
        // The route throttle would step in first; these tests are about what comes after it.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function failLogin(string $email, string $ip = '198.51.100.7'): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(self::LOGIN, ['email' => $email, 'password' => 'wrong']);
    }

    public function test_every_login_is_kept_in_the_history(): void
    {
        $client = Client::factory()->create(['password' => self::PASSWORD]);

        $this->failLogin($client->email);
        $this->failLogin('nobody@example.com');
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15')
            ->post(self::LOGIN, ['email' => $client->email, 'password' => self::PASSWORD])->assertRedirect('/portal');

        $attempts = LoginAttempt::orderBy('id')->get();
        $this->assertSame([LoginAttempt::FAILED, LoginAttempt::FAILED, LoginAttempt::SUCCESS], $attempts->pluck('outcome')->all());
        $this->assertSame([$client->id, null, $client->id], $attempts->pluck('client_id')->all());
        $this->assertSame('Safari on macOS', $attempts[2]->browser());

        $this->get('/portal/security')->assertOk()->assertSee('Wrong email or password')->assertSee('198.51.100.7');

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/clients/{$client->id}")->assertSee('Login and privacy')->assertSee('Signed in');
        $this->get(self::SECURITY.'?q=nobody')->assertOk()->assertSee('nobody@example.com')->assertDontSee($client->email);
        $this->get(self::SECURITY.'?outcome=success')->assertSee($client->fullName());
    }

    public function test_too_many_failures_lock_that_address_out_of_that_account(): void
    {
        $client = Client::factory()->create(['password' => self::PASSWORD]);

        for ($i = 0; $i < 5; $i++) {
            $this->failLogin($client->email);
        }

        // Locked out from this address, even with the right password.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post(self::LOGIN, ['email' => $client->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => 'Too many failed logins. Try again in 15 minutes, or reset your password.']);
        $this->assertGuest('client');
        $this->assertSame(LoginAttempt::LOCKED, LoginAttempt::latest('id')->first()->outcome);

        // The real owner, somewhere else, still gets in.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->post(self::LOGIN, ['email' => $client->email, 'password' => self::PASSWORD])->assertRedirect('/portal');
        $this->post('/portal/logout');

        // It ends once the failures are old enough.
        $this->travel(16)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post(self::LOGIN, ['email' => $client->email, 'password' => self::PASSWORD])->assertRedirect('/portal');
    }

    public function test_staff_logins_lock_out_too(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->post('/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest('web');
        $this->assertSame(6, LoginAttempt::where('area', LoginAttempt::ADMIN)->where('account_id', $user->id)->count());
    }

    public function test_an_address_failing_logins_to_many_accounts_is_banned(): void
    {
        config(['security.ban_after_failures' => 6, 'security.ban_hours' => 2]);

        foreach (range(1, 6) as $i) {
            $this->failLogin("guess{$i}@example.com");
        }

        $ban = IpBan::sole();
        $this->assertSame(['198.51.100.7', null], [$ban->ip_address, $ban->user_id]);
        $this->assertTrue($ban->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/portal/login')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/store')->assertForbidden();
        // Payment webhooks still get through.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/webhooks/paypal', [])->assertStatus(400);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->get('/portal/login')->assertOk();

        $this->travel(3)->hours();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/portal/login')->assertOk();
    }

    public function test_staff_ban_and_unban_addresses_and_ranges(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin, 'web');

        $this->post(self::SECURITY.'/bans', ['ip_address' => 'not-an-ip'])->assertSessionHasErrors('ip_address');
        $this->post(self::SECURITY.'/bans', ['ip_address' => '127.0.0.0/8'])->assertSessionHasErrors('ip_address');
        $this->post(self::SECURITY.'/bans', ['ip_address' => '203.0.113.0/24', 'reason' => 'Card testing', 'days' => 30])->assertRedirect();

        $ban = IpBan::sole();
        $this->assertSame([$admin->id, 'Card testing'], [$ban->user_id, $ban->reason]);
        $this->get(self::SECURITY)->assertSee('203.0.113.0/24')->assertSee('Card testing');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->get('/portal/login')->assertForbidden();

        $this->withServerVariables([])->delete(self::SECURITY."/bans/{$ban->id}")->assertRedirect();
        $this->assertSame(0, IpBan::count());
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->get('/portal/login')->assertOk();

        // Support staff can't change security.
        $this->actingAs(User::factory()->create(['role' => 'support']), 'web');
        $this->get(self::SECURITY)->assertForbidden();
    }

    public function test_security_settings_are_saved(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->get(self::SECURITY)->assertOk()->assertSee('Failed logins');

        $this->put(self::SECURITY, [
            'lockout_attempts' => 3, 'lockout_minutes' => 30, 'ban_after_failures' => '', 'ban_hours' => 12,
            'login_history_days' => 30, 'staff_two_factor' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertSame([3, 30, null, 30], [config('security.lockout_attempts'), config('security.lockout_minutes'), config('security.ban_after_failures'), config('security.login_history_days')]);

        $client = Client::factory()->create(['password' => self::PASSWORD]);
        foreach (range(1, 3) as $i) {
            $this->failLogin($client->email);
        }
        $this->post(self::LOGIN, ['email' => $client->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
    }

    public function test_logging_in_moves_old_password_hashes_to_the_current_cost(): void
    {
        $weak = Hash::make(self::PASSWORD, ['rounds' => 4]);
        $client = Client::factory()->create();
        $client->forceFill(['password' => $weak])->saveQuietly();
        $user = User::factory()->create();
        $user->forceFill(['password' => $weak])->saveQuietly();
        config(['hashing.bcrypt.rounds' => 5]);
        Hash::forgetDrivers();

        $this->post(self::LOGIN, ['email' => $client->email, 'password' => self::PASSWORD])->assertRedirect('/portal');
        $this->post('/admin/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/admin');

        foreach ([$client->fresh(), $user->fresh()] as $account) {
            $this->assertNotSame($weak, $account->password);
            $this->assertFalse(Hash::needsRehash($account->password));
            $this->assertTrue(Hash::check(self::PASSWORD, $account->password));
        }
    }

    public function test_old_login_history_and_ended_bans_are_pruned(): void
    {
        config(['security.login_history_days' => 30]);
        LoginAttempt::create(['area' => 'portal', 'email' => 'old@example.com', 'outcome' => 'failed', 'created_at' => now()->subDays(31)]);
        LoginAttempt::create(['area' => 'portal', 'email' => 'new@example.com', 'outcome' => 'failed', 'created_at' => now()->subDays(29)]);
        IpBan::create(['ip_address' => '192.0.2.1', 'expires_at' => now()->subMinute()]);
        IpBan::create(['ip_address' => '192.0.2.2']);

        $this->artisan('model:prune', ['--model' => [LoginAttempt::class, IpBan::class]])->assertSuccessful();

        $this->assertSame(['new@example.com'], LoginAttempt::pluck('email')->all());
        $this->assertSame(['192.0.2.2'], IpBan::pluck('ip_address')->all());
    }
}
