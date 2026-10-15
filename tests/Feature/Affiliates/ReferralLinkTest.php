<?php

namespace Tests\Feature\Affiliates;

use App\Affiliates\Referrals;
use App\Models\Activity;
use App\Models\Affiliate;
use App\Models\AffiliateReferral;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReferralLinkTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-password';

    private Affiliate $affiliate;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['affiliates.enabled' => true]);
        $this->affiliate = app(Referrals::class)->join(Client::factory()->create());
    }

    public function test_the_link_sets_a_cookie_counts_the_visit_and_goes_to_the_store(): void
    {
        $this->get("/r/{$this->affiliate->code}")
            ->assertRedirect(route('store.index'))
            ->assertCookie(Referrals::COOKIE, $this->affiliate->code);
        $this->assertSame(1, $this->affiliate->fresh()->visits);

        // The same browser coming back isn't counted twice.
        $this->withCookie(Referrals::COOKIE, $this->affiliate->code)->get("/r/{$this->affiliate->code}")->assertRedirect(route('store.index'));
        $this->assertSame(1, $this->affiliate->fresh()->visits);
    }

    public function test_the_link_only_sends_visitors_to_pages_on_this_site(): void
    {
        $link = "/r/{$this->affiliate->code}";

        $this->get("{$link}?to=/store/servers")->assertRedirect(url('/store/servers'));
        foreach (['//evil.example', 'https://evil.example/x', '/\\evil.example', 'javascript:alert(1)', "/ok\nLocation: x"] as $to) {
            $this->get($link.'?to='.urlencode($to))->assertRedirect(route('store.index'));
        }
    }

    public function test_nothing_is_tracked_when_the_program_is_off_or_the_code_is_unknown(): void
    {
        $this->get('/r/nope1234')->assertRedirect(route('store.index'))->assertCookieMissing(Referrals::COOKIE);

        config(['affiliates.enabled' => false]);
        $this->get("/r/{$this->affiliate->code}")->assertRedirect(route('store.index'))->assertCookieMissing(Referrals::COOKIE);

        config(['affiliates.enabled' => true]);
        $this->affiliate->update(['status' => Affiliate::DISABLED]);
        $this->get("/r/{$this->affiliate->code}")->assertCookieMissing(Referrals::COOKIE);
        $this->assertSame(0, $this->affiliate->fresh()->visits);
    }

    public function test_old_whmcs_links_work_for_imported_affiliates(): void
    {
        $this->affiliate->forceFill(['code' => '42'])->save();

        $this->get('/aff.php?aff=42')->assertRedirect(route('store.index'))->assertCookie(Referrals::COOKIE, '42');
        $this->assertSame(1, $this->affiliate->fresh()->visits);
    }

    public function test_a_visitor_who_signs_up_at_checkout_becomes_a_referral(): void
    {
        $this->cartWithAProduct();

        $this->withCookie(Referrals::COOKIE, $this->affiliate->code)
            ->post('/checkout', $this->signUp('ada@example.com'))
            ->assertCookieExpired(Referrals::COOKIE);

        $client = Client::where('email', 'ada@example.com')->sole();
        $this->assertSame($this->affiliate->id, $client->referral->affiliate_id);
        $this->assertTrue(Activity::where('client_id', $client->id)->where('description', 'like', 'Referred by affiliate%')->exists());
    }

    public function test_sign_ups_without_a_valid_cookie_have_no_referrer(): void
    {
        $this->cartWithAProduct();
        $this->post('/checkout', $this->signUp('bob@example.com'));
        $this->assertNull(Client::where('email', 'bob@example.com')->sole()->referral);

        $this->affiliate->update(['status' => Affiliate::DISABLED]);
        $this->post('/portal/logout');
        $this->cartWithAProduct();
        $this->withCookie(Referrals::COOKIE, $this->affiliate->code)->post('/checkout', $this->signUp('cy@example.com'));
        $this->assertNull(Client::where('email', 'cy@example.com')->sole()->referral);
    }

    public function test_staff_set_change_and_remove_a_clients_referrer(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        $other = app(Referrals::class)->join(Client::factory()->create());

        $this->get(route('admin.clients.show', $client))->assertOk()->assertSee('Not an affiliate')->assertSee('Nobody');

        $this->put(route('admin.affiliates.referrer', $client), ['referrer_code' => strtoupper($this->affiliate->code)])->assertSessionHas('status');
        $this->assertSame($this->affiliate->id, $client->referral()->value('affiliate_id'));
        $this->get(route('admin.clients.show', $client))->assertSee($this->affiliate->client->fullName());

        $this->put(route('admin.affiliates.referrer', $client), ['referrer_code' => $other->code]);
        $this->assertSame($other->id, $client->referral()->value('affiliate_id'));

        $this->put(route('admin.affiliates.referrer', $client), ['referrer_code' => 'nobody99'])->assertSessionHasErrors('referrer_code');
        $this->put(route('admin.affiliates.referrer', $other->client), ['referrer_code' => $other->code])->assertSessionHasErrors('referrer_code');

        $this->put(route('admin.affiliates.referrer', $client), ['referrer_code' => '']);
        $this->assertSame(0, AffiliateReferral::where('client_id', $client->id)->count());
    }

    private function cartWithAProduct(): void
    {
        $group = ProductGroup::firstOrCreate(['slug' => 'servers'], ['name' => 'Servers']);
        $product = Product::factory()->create(['product_group_id' => $group->id]);
        $product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 1000]);
        $this->post(route('cart.store', $product), ['billing_cycle' => 'monthly'])->assertRedirect(route('cart.index'));
    }

    private function signUp(string $email): array
    {
        return [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => $email,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'address1' => '1 Main St', 'city' => 'Austin', 'postcode' => '78701', 'country' => 'us',
        ];
    }
}
