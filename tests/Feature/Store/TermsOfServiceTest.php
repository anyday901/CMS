<?php

namespace Tests\Feature\Store;

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TermsOfServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TERMS_URL = 'https://example.com/terms';

    private const CHECKOUT = '/checkout';

    private Product $vps;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->vps = Product::factory()->create(['name' => 'VPS']);
        $this->vps->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 1000]);
    }

    private function signUpDetails(array $overrides = []): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
            'address1' => '1 Main St',
            'city' => 'Austin',
            'postcode' => '78701',
            'country' => 'US',
            ...$overrides,
        ];
    }

    public function test_without_a_terms_link_there_is_no_checkbox(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($client, 'client');
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => 'monthly']);

        $this->get(self::CHECKOUT)->assertOk()->assertDontSee('terms of service');
        $this->post(self::CHECKOUT)->assertRedirect();

        $this->assertNull(Order::sole()->terms_accepted_at);
    }

    public function test_guests_must_agree_before_an_account_is_made(): void
    {
        config(['store.terms_url' => self::TERMS_URL]);
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => 'monthly']);

        $this->get(self::CHECKOUT)->assertOk()->assertSee(self::TERMS_URL)->assertSee('name="accept_terms"', false);
        $this->post(self::CHECKOUT, $this->signUpDetails())->assertSessionHasErrors('accept_terms');

        $this->assertSame(0, Client::count());
        $this->assertSame(0, Order::count());
        $this->assertGuest('client');

        $this->post(self::CHECKOUT, $this->signUpDetails(['accept_terms' => '1']))->assertRedirect();
        $this->assertNotNull(Order::sole()->terms_accepted_at);
    }

    public function test_logged_in_clients_agree_too_and_staff_see_it_on_the_order(): void
    {
        config(['store.terms_url' => self::TERMS_URL]);
        $client = Client::factory()->create();
        $this->actingAs($client, 'client');
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => 'monthly']);

        $this->post(self::CHECKOUT)->assertSessionHasErrors('accept_terms');
        $this->assertSame(0, Order::count());

        $this->post(self::CHECKOUT, ['accept_terms' => '1'])->assertRedirect();
        $order = Order::sole();
        $this->assertNotNull($order->terms_accepted_at);

        $this->actingAs(User::factory()->create(), 'web')
            ->get(route('admin.orders.show', $order))->assertOk()->assertSee('Agreed to the terms of service');
    }

    public function test_staff_set_the_terms_link_in_settings(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->put('/admin/settings', ['terms_url' => 'not a link'])->assertSessionHasErrors('terms_url');
        $this->put('/admin/settings', ['terms_url' => self::TERMS_URL])->assertRedirect('/admin/settings');
        $this->assertSame(self::TERMS_URL, config('store.terms_url'));
        $this->get('/admin/settings')->assertOk()->assertSee(self::TERMS_URL);

        $this->put('/admin/settings', ['terms_url' => ''])->assertRedirect('/admin/settings');
        $this->assertNull(config('store.terms_url'));
    }
}
