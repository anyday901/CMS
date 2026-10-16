<?php

namespace Tests\Feature\Platform;

use App\Enums\StaffRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/mail';

    private const SMTP_HOST = 'smtp.example.com';

    private function smtp(array $overrides = []): array
    {
        return $overrides + [
            'mailer' => 'smtp', 'scheme' => 'smtp', 'host' => self::SMTP_HOST, 'port' => 587,
            'username' => 'mailer', 'password' => 'hunter2', 'from_address' => 'billing@example.com', 'from_name' => 'Example Hosting',
        ];
    }

    public function test_staff_save_smtp_settings_and_the_password_is_kept_encrypted(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(self::URL)->assertOk()->assertSee('Mail settings');
        $this->put(self::URL, $this->smtp())->assertRedirect(self::URL)->assertSessionHas('status', 'Mail settings saved.');

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame(self::SMTP_HOST, config('mail.mailers.smtp.host'));
        $this->assertSame('hunter2', config('mail.mailers.smtp.password'));
        $this->assertSame('hunter2', Crypt::decryptString(Setting::find('mail.mailers.smtp.password')->value));

        // A blank password keeps the saved one; ticking the box removes it.
        $this->put(self::URL, $this->smtp(['password' => '', 'host' => 'mail.example.com']))->assertRedirect(self::URL);
        $this->assertSame('hunter2', config('mail.mailers.smtp.password'));
        $this->assertSame('mail.example.com', config('mail.mailers.smtp.host'));

        $this->put(self::URL, $this->smtp(['password' => '', 'forget_password' => '1']))->assertRedirect(self::URL);
        $this->assertNull(config('mail.mailers.smtp.password'));
        $this->get(self::URL)->assertDontSee('Saved; leave blank to keep it');
    }

    public function test_smtp_needs_a_server_and_port(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put(self::URL, $this->smtp(['host' => '', 'port' => '']))->assertSessionHasErrors(['host', 'port']);
        $this->put(self::URL, $this->smtp(['mailer' => 'log', 'host' => '', 'port' => '']))->assertSessionHasNoErrors();
        $this->assertSame('log', config('mail.default'));
    }

    public function test_a_test_email_is_sent_straight_away(): void
    {
        $this->actingAs(User::factory()->create());
        config(['mail.default' => 'array']);

        $this->post(self::URL.'/test', ['to' => 'me@example.com'])->assertSessionHas('status', 'Test email sent to me@example.com.');
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Test email from', $sent->first()->getOriginalMessage()->getSubject());
    }

    public function test_a_failing_test_email_shows_the_error(): void
    {
        $this->actingAs(User::factory()->create());
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

        $this->post(self::URL.'/test', ['to' => 'me@example.com'])->assertSessionHasErrors('to');
        $this->assertStringContainsString('The test email wasn\'t sent', session('errors')->first('to'));
    }

    public function test_only_staff_who_manage_settings_see_mail_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]));

        $this->get(self::URL)->assertForbidden();
        $this->put(self::URL, $this->smtp())->assertForbidden();
    }
}
