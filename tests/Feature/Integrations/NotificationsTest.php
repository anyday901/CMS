<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Chat\ChatMessage;
use App\Integrations\Sms\Sms;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Department;
use App\Models\NotificationChannel;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Support\Settings;
use App\Tickets\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const SLACK = 'https://hooks.slack.com/services/T/B/x';

    private const TWILIO = 'api.twilio.com/*';

    private function useTwilio(array $settings = []): void
    {
        Settings::save([
            'sms.provider' => 'twilio',
            'sms.from' => '+15550000000',
            'sms.credentials' => json_encode(['account_sid' => 'AC123', 'auth_token' => 'secret']),
            'sms.default_country_code' => '1',
        ] + $settings);
    }

    public function test_each_chat_service_gets_its_own_message_format(): void
    {
        $slack = ChatMessage::for('slack', 'New order from <Jane> & co', 'https://x.test/o/1', 'Open');
        $this->assertSame('New order from &lt;Jane&gt; &amp; co <https://x.test/o/1|Open>', $slack['text']);

        $discord = ChatMessage::for('discord', 'Ticket from @everyone *bold*', 'https://x.test/t/1');
        $this->assertStringContainsString("@\u{200B}everyone \\*bold\\*", $discord['content']);
        $this->assertSame(['parse' => []], $discord['allowed_mentions']);

        $teams = ChatMessage::for('teams', 'Invoice paid', 'https://x.test/i/1', 'Open');
        $card = $teams['attachments'][0]['content'];
        $this->assertSame('AdaptiveCard', $card['type']);
        $this->assertSame('Invoice paid', $card['body'][0]['text']);
        $this->assertSame('https://x.test/i/1', $card['actions'][0]['url']);
    }

    public function test_events_and_staff_alerts_reach_the_channels_that_want_them(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        NotificationChannel::create(['type' => 'slack', 'name' => '#support', 'webhook_url' => self::SLACK, 'events' => ['ticket.opened', 'staff.alert'], 'active' => true]);
        NotificationChannel::create(['type' => 'discord', 'name' => 'Billing', 'webhook_url' => 'https://discord.com/api/webhooks/1/x', 'events' => ['invoice.paid'], 'active' => true]);

        $ticket = app(TicketDesk::class)->open(Client::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']), Department::create(['name' => 'Support']), 'Site down', 'Help');
        StaffAlert::send('manage-clients', 'Provisioning failed', ['It broke'], 'View', 'https://x.test/s/1');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->url() === self::SLACK && str_contains($r['text'], "New ticket #{$ticket->id} from Jane Doe in Support: Site down"));
        Http::assertSent(fn (Request $r) => $r->url() === self::SLACK && str_contains($r['text'], 'Provisioning failed <https://x.test/s/1|Open>'));
        $this->assertNotNull(NotificationChannel::where('name', '#support')->value('last_sent_at'));
    }

    public function test_staff_add_channels_and_send_a_test(): void
    {
        Http::fake([self::SLACK => Http::sequence()->push('ok')->push('invalid_token', 403)]);
        $this->actingAs(User::factory()->create());

        $this->get('/admin/settings/chat')->assertOk()->assertSee('No channels yet.');
        $this->post('/admin/settings/chat', ['type' => 'slack', 'name' => '#ops', 'webhook_url' => 'http://insecure.test', 'events' => ['order.placed']])->assertSessionHasErrors('webhook_url');
        $this->post('/admin/settings/chat', ['type' => 'slack', 'name' => '#ops', 'webhook_url' => self::SLACK, 'events' => ['order.placed'], 'active' => '1'])->assertRedirect();
        $channel = NotificationChannel::sole();
        $this->assertSame(self::SLACK, $channel->webhook_url);
        $this->assertNotSame(self::SLACK, $channel->getRawOriginal('webhook_url'));

        $this->post("/admin/settings/chat/{$channel->id}/test")->assertSessionHas('status');
        $this->post("/admin/settings/chat/{$channel->id}/test")->assertSessionHasErrors('test');
        $this->assertStringContainsString('HTTP 403', $channel->fresh()->last_error);

        // A blank URL keeps the saved one.
        $this->get("/admin/settings/chat/{$channel->id}/edit")->assertOk()->assertDontSee(self::SLACK);
        $this->put("/admin/settings/chat/{$channel->id}", ['type' => 'slack', 'name' => '#ops2', 'webhook_url' => '', 'events' => ['order.placed'], 'active' => '1'])->assertRedirect();
        $this->assertSame(self::SLACK, $channel->fresh()->webhook_url);
        $this->assertSame('#ops2', $channel->fresh()->name);
    }

    public function test_phone_numbers_are_put_in_international_form(): void
    {
        config(['sms.default_country_code' => '1']);
        $this->assertSame('+15551234567', Sms::normalise('(555) 123-4567'));
        $this->assertSame('+15551234567', Sms::normalise('1 555 123 4567'));
        $this->assertSame('+447700900123', Sms::normalise('+44 7700 900123'));
        $this->assertSame('+447700900123', Sms::normalise('0044 7700 900123'));
        $this->assertNull(Sms::normalise('call me'));
        $this->assertNull(Sms::normalise(''));

        config(['sms.default_country_code' => '44']);
        $this->assertSame('+447700900123', Sms::normalise('07700 900123'));

        // Digits alone are a national number, even when they start with the country code; international ones need + or 00.
        config(['sms.default_country_code' => '65']);
        $this->assertSame('+6591234567', Sms::normalise('+65 9123 4567'));
        config(['sms.default_country_code' => '91']);
        $this->assertSame('+919123456789', Sms::normalise('9123456789'));
    }

    public function test_staff_are_texted_about_their_events_and_clients_only_when_they_agreed(): void
    {
        Http::fake([self::TWILIO => Http::response(['sid' => 'SM1'], 201)]);
        $this->useTwilio(['sms.staff_numbers' => ['+15559990000'], 'sms.staff_events' => ['ticket.opened'], 'sms.client_events' => ['ticket.answered']]);
        $desk = app(TicketDesk::class);
        $department = Department::create(['name' => 'Support']);

        $agreed = Client::factory()->create(['phone' => '555 123 4567']);
        $agreed->forceFill(['sms_notifications' => true])->save();
        $ticket = $desk->open($agreed, $department, 'Help', 'Please');
        $desk->staffReply($ticket, User::factory()->create(), 'Done');

        $notAgreed = Client::factory()->create(['phone' => '555 765 4321']);
        $desk->staffReply($desk->open($notAgreed, $department, 'Other', 'Hi'), User::factory()->create(), 'Done');

        $texts = SmsLog::orderBy('id')->get();
        $this->assertSame(['+15559990000', '+15551234567', '+15559990000'], $texts->pluck('to')->all());
        $this->assertTrue($texts->every(fn (SmsLog $text) => $text->status === SmsLog::SENT && $text->provider_reference === 'SM1'));
        $this->assertStringContainsString("we replied to your ticket #{$ticket->id} (Help)", $texts[1]->message);
        $this->assertSame($agreed->id, $texts[1]->client_id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/Accounts/AC123/Messages.json') && $r['To'] === '+15551234567' && $r['From'] === '+15550000000'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('AC123:secret')));
    }

    public function test_a_refused_text_is_logged_with_the_reason(): void
    {
        Http::fake([self::TWILIO => Http::response(['message' => 'Invalid number'], 400)]);
        $this->useTwilio();

        $log = app(Sms::class)->queue('+15551234567', 'Hello');

        $this->assertSame(SmsLog::FAILED, $log->fresh()->status);
        $this->assertSame('Twilio refused the message: Invalid number', $log->fresh()->error);
    }

    public function test_vonage_sends_too(): void
    {
        Http::fake(['rest.nexmo.com/*' => Http::response(['messages' => [['status' => '0', 'message-id' => 'V1']]])]);
        Settings::save(['sms.provider' => 'vonage', 'sms.from' => 'Acme', 'sms.credentials' => json_encode(['api_key' => 'k', 'api_secret' => 's'])]);

        $log = app(Sms::class)->queue('+447700900123', 'Hi');

        $this->assertSame('V1', $log->fresh()->provider_reference);
        Http::assertSent(fn (Request $r) => $r['to'] === '447700900123' && $r['from'] === 'Acme' && $r['api_secret'] === 's');
    }

    public function test_staff_set_up_sms_and_secrets_are_kept_encrypted(): void
    {
        Http::fake([self::TWILIO => Http::response(['sid' => 'SM9'], 201)]);
        $this->actingAs(User::factory()->create());

        $this->get('/admin/settings/sms')->assertOk()->assertSee('Twilio')->assertSee('None sent yet.');
        $this->put('/admin/settings/sms', ['provider' => 'twilio', 'from' => '+15550000000', 'staff_numbers' => "+15559990000\nnot a number"])->assertSessionHasErrors('staff_numbers');

        $this->put('/admin/settings/sms', [
            'provider' => 'twilio', 'from' => '+15550000000', 'default_country_code' => '+1',
            'credentials' => ['account_sid' => 'AC1', 'auth_token' => 'tok-9f3a'],
            'staff_numbers' => "+15559990000\n(555) 222-3333", 'staff_events' => ['order.placed'], 'client_events' => ['invoice.paid'],
        ])->assertSessionHas('status');
        $this->assertSame(['account_sid' => 'AC1', 'auth_token' => 'tok-9f3a'], app(Sms::class)->credentials());
        $this->assertStringNotContainsString('tok-9f3a', (string) Setting::find('sms.credentials')->value);
        $this->assertSame('1', config('sms.default_country_code'));

        // A blank secret keeps the saved token, and the page doesn't show it.
        $this->get('/admin/settings/sms')->assertDontSee('tok-9f3a')->assertSee('Saved; leave blank to keep it');
        $this->put('/admin/settings/sms', ['provider' => 'twilio', 'from' => '+15550000000', 'credentials' => ['account_sid' => 'AC2', 'auth_token' => '']]);
        $this->assertSame(['account_sid' => 'AC2', 'auth_token' => 'tok-9f3a'], app(Sms::class)->credentials());

        $this->post('/admin/settings/sms/test', ['to' => 'nope'])->assertSessionHasErrors('to');
        $this->post('/admin/settings/sms/test', ['to' => '+15551112222'])->assertSessionHas('status');
        $this->assertSame(SmsLog::SENT, SmsLog::sole()->status);
    }

    public function test_clients_agree_to_texts_in_the_portal_when_texts_are_offered(): void
    {
        $client = Client::factory()->create(['phone' => '555 123 4567']);
        $this->actingAs($client, 'client');

        $this->get('/portal/account')->assertDontSee('Text me about my account');

        $this->useTwilio(['sms.client_events' => ['invoice.paid']]);
        $this->get('/portal/account')->assertSee('Text me about my account');
        $this->put('/portal/account/marketing', ['marketing_emails' => '0', 'sms_notifications' => '1'])->assertSessionHas('status');
        $this->assertTrue($client->fresh()->sms_notifications);
        $this->assertSame(1, Activity::where('description', 'Agreed to account texts')->count());

        $this->put('/portal/account/marketing', ['marketing_emails' => '0', 'sms_notifications' => '0']);
        $this->assertFalse($client->fresh()->sms_notifications);
    }
}
