<?php

namespace Tests\Feature\Integrations;

use App\Billing\InvoicePdf;
use App\Http\Controllers\MarketingUnsubscribeController;
use App\Jobs\SendLoggedEmail;
use App\Mail\LoggedEmail;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\EmailLog;
use App\Models\MassMail;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MassMailTest extends TestCase
{
    use RefreshDatabase;

    private const FILTERS = ['statuses' => ['active']];

    private function client(bool $marketing, array $attributes = []): Client
    {
        $client = Client::factory()->create($attributes);
        $client->setMarketingConsent($marketing);
        $client->save();

        return $client;
    }

    public function test_marketing_emails_only_reach_clients_who_agreed_with_their_unsubscribe_link(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        $yes = $this->client(true, ['first_name' => 'Ana']);
        $this->client(false);
        $this->client(true, ['status' => 'closed']);

        $this->get('/admin/mass-mail')->assertOk()->assertSee('Nothing sent yet.');
        $this->get('/admin/mass-mail/create')->assertOk();
        $this->post('/admin/mass-mail/preview', ['subject' => 'News for {client_first_name}', 'body' => 'Hello {client_first_name}', 'marketing' => '1', 'filters' => self::FILTERS])
            ->assertOk()->assertSee('1 client</strong> who agreed to marketing emails', false)->assertSee('News for Ana');

        $this->post('/admin/mass-mail', ['subject' => 'News for {client_first_name}', 'body' => 'Hello {client_first_name}', 'marketing' => '1', 'filters' => self::FILTERS])->assertRedirect();

        $mail = MassMail::sole();
        $this->assertSame(MassMail::SENT, $mail->status);
        $this->assertSame(1, $mail->recipients);
        $log = EmailLog::sole();
        $this->assertSame($yes->id, $log->client_id);
        $this->assertSame('News for Ana', $log->subject);
        $this->assertSame(MarketingUnsubscribeController::url($yes), $log->list_unsubscribe);
        $this->assertStringContainsString('[Unsubscribe]('.MarketingUnsubscribeController::url($yes).')', $log->body);
        Mail::assertSent(LoggedEmail::class, fn (LoggedEmail $email) => $email->headers()->text === [
            'List-Unsubscribe' => '<'.MarketingUnsubscribeController::url($yes).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
        $this->get("/admin/mass-mail/{$mail->id}")->assertOk()->assertSee($yes->email);
    }

    public function test_service_notices_go_to_everyone_picked_without_an_unsubscribe_link(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        $this->client(true);
        $this->client(false);

        $this->post('/admin/mass-mail', ['subject' => 'Maintenance', 'body' => 'Tonight', 'marketing' => '0', 'filters' => self::FILTERS]);

        $this->assertSame(2, EmailLog::where('status', EmailLog::SENT)->count());
        $this->assertNull(EmailLog::first()->list_unsubscribe);
        Mail::assertSent(LoggedEmail::class, fn (LoggedEmail $email) => $email->headers()->text === []);
    }

    public function test_filters_pick_clients_by_group_country_and_product(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        $group = ClientGroup::create(['name' => 'Resellers', 'colour' => '#123456']);
        $product = Product::factory()->create();
        $match = $this->client(false, ['client_group_id' => $group->id, 'country' => 'US']);
        Service::factory()->create(['client_id' => $match->id, 'product_id' => $product->id, 'status' => 'active']);
        $cancelled = $this->client(false, ['client_group_id' => $group->id, 'country' => 'US']);
        Service::factory()->create(['client_id' => $cancelled->id, 'product_id' => $product->id, 'status' => 'cancelled']);
        $this->client(false, ['client_group_id' => $group->id, 'country' => 'GB']);

        $filters = self::FILTERS + ['group' => (string) $group->id, 'country' => 'US', 'product_ids' => [$product->id]];
        $this->post('/admin/mass-mail/preview', ['subject' => 'S', 'body' => 'B', 'marketing' => '0', 'filters' => $filters])->assertSee('1 client</strong>', false);
        $this->post('/admin/mass-mail', ['subject' => 'S', 'body' => 'B', 'marketing' => '0', 'filters' => $filters]);
        $this->assertSame([$match->id], EmailLog::pluck('client_id')->all());

        $this->post('/admin/mass-mail', ['subject' => 'S', 'body' => 'B', 'marketing' => '0', 'filters' => self::FILTERS + ['country' => 'FR']])
            ->assertSessionHasErrors('filters');
    }

    public function test_a_client_who_unsubscribes_before_it_goes_out_is_skipped(): void
    {
        Mail::fake();
        $client = $this->client(true);
        $mail = MassMail::create(['subject' => 'Offer', 'body' => 'Buy', 'marketing' => true, 'filters' => self::FILTERS]);
        $log = EmailLog::create(['client_id' => $client->id, 'mass_mail_id' => $mail->id, 'template' => 'mass_mail', 'to' => $client->email, 'subject' => 'Offer', 'body' => 'Buy', 'status' => EmailLog::QUEUED]);

        $client->setMarketingConsent(false);
        $client->save();
        (new SendLoggedEmail($log->id))->handle(app(InvoicePdf::class));

        Mail::assertNothingSent();
        $this->assertSame(EmailLog::FAILED, $log->fresh()->status);
        $this->assertStringContainsString('unsubscribed', $log->fresh()->error);
    }

    public function test_support_staff_cant_send_mass_mail(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']));

        $this->get('/admin/mass-mail')->assertForbidden();
    }
}
