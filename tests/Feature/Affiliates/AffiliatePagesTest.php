<?php

namespace Tests\Feature\Affiliates;

use App\Affiliates\Referrals;
use App\Enums\ClientStatus;
use App\Enums\StaffRole;
use App\Hooks\HookCatalog;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\Client;
use App\Models\User;
use App\Privacy\ClientAnonymiser;
use App\Privacy\ClientDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliatePagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '/portal/affiliates';

    public function test_the_hook_events_are_listed_for_webhooks_and_chat_channels(): void
    {
        $this->assertSame(['New affiliate', 'Affiliate commission earned', 'Affiliate payout requested'], array_values(array_intersect_key(HookCatalog::events(), array_flip(['affiliate.signup', 'affiliate.commission', 'affiliate.payout_requested']))));
    }

    public function test_clients_join_from_the_portal_when_self_sign_up_is_on(): void
    {
        $fired = [];
        app(Hooks::class)->listen('affiliate.signup', function (HookEvent $event) use (&$fired) {
            $fired[] = $event;
        });
        $client = Client::factory()->create();
        $this->actingAs($client, 'client');

        $this->get(self::PAGE)->assertOk()->assertSee('open at the moment');
        $this->post(self::PAGE)->assertSessionHasErrors('affiliate');

        config(['affiliates.enabled' => true]);
        $this->get(self::PAGE)->assertOk()->assertSee('Join the affiliate program')->assertSee('10%');
        $this->post(self::PAGE)->assertRedirect(self::PAGE);

        $affiliate = $client->affiliate()->sole();
        $this->assertSame(Affiliate::ACTIVE, $affiliate->status);
        $this->assertCount(1, $fired);
        $this->assertSame(route('admin.affiliates.show', $affiliate), $fired[0]->url);
        $this->get(self::PAGE)->assertOk()->assertSee($affiliate->link())->assertSee('Copy link')->assertSee('Nothing to pay out yet');
    }

    public function test_when_self_sign_up_is_off_clients_are_told_to_ask(): void
    {
        config(['affiliates.enabled' => true, 'affiliates.self_signup' => false]);
        $this->actingAs(Client::factory()->create(), 'client');

        $this->get(self::PAGE)->assertOk()->assertDontSee('Join the affiliate program')->assertSee('contact us');
        $this->post(self::PAGE)->assertSessionHasErrors('affiliate');
        $this->assertSame(0, Affiliate::count());
    }

    public function test_the_portal_page_shows_visits_sign_ups_and_commissions_but_not_who_signed_up(): void
    {
        config(['affiliates.enabled' => true]);
        $affiliate = app(Referrals::class)->join(Client::factory()->create());
        $affiliate->forceFill(['visits' => 12])->save();
        $referred = Client::factory()->create(['first_name' => 'Secretname']);
        AffiliateReferral::create(['affiliate_id' => $affiliate->id, 'client_id' => $referred->id]);
        AffiliateCommission::create(['affiliate_id' => $affiliate->id, 'amount' => 3000, 'original_amount' => 3000, 'currency' => 'USD', 'status' => AffiliateCommission::APPROVED]);

        $this->actingAs($affiliate->client, 'client')->get(self::PAGE)->assertOk()
            ->assertSee('12')->assertSee('Ask for a payout of $30.00')->assertDontSee('Secretname');
    }

    public function test_contacts_without_the_account_holders_access_cannot_open_it(): void
    {
        $client = Client::factory()->create();
        $contact = $client->contacts()->create(['first_name' => 'Bob', 'last_name' => 'Books', 'email' => 'bob@example.com', 'portal_access' => true, 'permissions' => ['billing'], 'password' => 'a-long-password']);
        $this->actingAs($client, 'client')->withSession(['portal_contact_id' => $contact->id]);

        $this->get(self::PAGE)->assertRedirect()->assertSessionHasErrors();
    }

    public function test_staff_list_search_and_manage_affiliates(): void
    {
        config(['affiliates.enabled' => true]);
        $this->actingAs(User::factory()->create());
        $ada = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $bob = Client::factory()->create(['first_name' => 'Bob', 'last_name' => 'Builder']);

        $this->get(route('admin.clients.show', $ada))->assertOk()->assertSee('Make affiliate');
        $this->post(route('admin.affiliates.store'), ['client_id' => $ada->id])->assertRedirect();
        app(Referrals::class)->join($bob);
        $affiliate = $ada->affiliate()->sole();

        $this->get(route('admin.affiliates.index'))->assertOk()->assertSee('Ada Lovelace')->assertSee('Bob Builder');
        $this->get(route('admin.affiliates.index', ['q' => 'ada love']))->assertOk()->assertSee('Ada Lovelace')->assertDontSee('Bob Builder');
        $this->get(route('admin.affiliates.index', ['q' => $affiliate->code]))->assertOk()->assertSee('Ada Lovelace')->assertDontSee('Bob Builder');

        $this->get(route('admin.affiliates.show', $affiliate))->assertOk()->assertSee($affiliate->link())->assertSee('10%');
        $this->put(route('admin.affiliates.update', $affiliate), ['status' => 'disabled', 'commission_type' => 'percent', 'commission_amount' => '150'])->assertSessionHasErrors('commission_amount');
        $this->put(route('admin.affiliates.update', $affiliate), ['status' => 'disabled', 'commission_type' => 'fixed', 'commission_amount' => '5.00'])->assertSessionHas('status');
        $this->assertSame([Affiliate::DISABLED, Affiliate::FIXED, 500], [$affiliate->fresh()->status, $affiliate->fresh()->commission_type, $affiliate->fresh()->commission_value]);
        $this->get(route('admin.affiliates.show', $affiliate))->assertSee('$5.00 per invoice')->assertSee('Turned off');
        $this->get(route('admin.clients.show', $ada))->assertSee($affiliate->code)->assertSee('referrals and commissions');

        $this->put(route('admin.affiliates.update', $affiliate), ['status' => 'active', 'commission_type' => '']);
        $this->assertNull($affiliate->fresh()->commission_type);
    }

    public function test_affiliate_settings_are_saved_and_used(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('admin.affiliates.settings'))->assertOk()->assertSee('Save affiliate settings');
        $this->put(route('admin.affiliates.settings.update'), [
            'enabled' => '1', 'self_signup' => '0', 'commission_type' => 'percent', 'commission_amount' => '12.5',
            'first_invoice_only' => '1', 'months' => '12', 'clearing_days' => '14', 'minimum_payout' => '50', 'cookie_days' => '30',
        ])->assertRedirect(route('admin.affiliates.settings'));

        $this->assertTrue(config('affiliates.enabled'));
        $this->assertFalse(config('affiliates.self_signup'));
        $this->assertSame([Affiliate::PERCENT, 1250], (new Affiliate)->commissionRate());
        $this->assertSame(['50.00', 12, 14, 30, true], [config('affiliates.minimum_payout'), config('affiliates.months'), config('affiliates.clearing_days'), config('affiliates.cookie_days'), config('affiliates.first_invoice_only')]);
        $this->get(route('admin.affiliates.settings'))->assertSee('12.50');

        $this->put(route('admin.affiliates.settings.update'), ['commission_type' => 'percent', 'commission_amount' => '101', 'clearing_days' => '0', 'minimum_payout' => '1', 'cookie_days' => '1'])
            ->assertSessionHasErrors('commission_amount');
    }

    public function test_billing_staff_manage_affiliates_but_only_admins_change_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Billing]));
        $this->get(route('admin.affiliates.index'))->assertOk();
        $this->get(route('admin.affiliates.payouts'))->assertOk();
        $this->get(route('admin.affiliates.settings'))->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]));
        $this->get(route('admin.affiliates.index'))->assertForbidden();
        $client = Client::factory()->create();
        $this->get(route('admin.clients.show', $client))->assertOk()->assertDontSee('Make affiliate');
    }

    public function test_the_data_export_includes_the_affiliate_program_without_the_people_referred(): void
    {
        config(['affiliates.enabled' => true]);
        $affiliate = app(Referrals::class)->join(Client::factory()->create());
        AffiliateReferral::create(['affiliate_id' => $affiliate->id, 'client_id' => Client::factory()->create(['email' => 'referred@example.com'])->id]);

        $data = app(ClientDataExport::class)->build($affiliate->client);

        $this->assertSame($affiliate->code, $data['affiliate']['program']['code']);
        $this->assertCount(1, $data['affiliate']['program']['referrals']);
        $this->assertStringNotContainsString('referred@example.com', json_encode($data));
    }

    public function test_anonymising_an_affiliate_turns_their_link_off(): void
    {
        config(['affiliates.enabled' => true]);
        $client = Client::factory()->create(['status' => ClientStatus::Closed]);
        $affiliate = app(Referrals::class)->join($client);

        app(ClientAnonymiser::class)->anonymise($client);

        $this->assertSame(Affiliate::DISABLED, $affiliate->fresh()->status);
        $this->get("/r/{$affiliate->code}")->assertCookieMissing(Referrals::COOKIE);
    }
}
