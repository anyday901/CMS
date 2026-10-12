<?php

namespace Tests\Feature\Store;

use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StockControlTest extends TestCase
{
    use RefreshDatabase;

    private const MONTHLY = 'monthly';

    private const CART = '/cart';

    private Product $vps;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->vps = Product::factory()->create(['name' => 'VPS', 'stock_control' => true, 'stock_quantity' => 1]);
        $this->vps->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 1000]);
        $this->actingAs(Client::factory()->create(), 'client');
    }

    public function test_orders_take_stock_and_the_cart_refuses_more_than_is_left(): void
    {
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY])->assertSessionHasNoErrors();
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY]);

        $this->get(self::CART)->assertSee('Only 1 of VPS left in stock.')->assertDontSee('Check out');
        $this->post('/checkout')->assertRedirect(route('cart.index'));
        $this->assertSame(0, Service::count());

        $line = collect(session('cart.lines'))->keys()->last();
        $this->delete(route('cart.destroy', $line));
        $this->post('/checkout')->assertRedirect();

        $this->assertSame(1, Service::count());
        $this->assertSame(0, $this->vps->refresh()->stock_quantity);
    }

    public function test_out_of_stock_products_cannot_be_ordered(): void
    {
        $this->vps->update(['stock_quantity' => 0]);

        $this->get('/store')->assertSee('Out of stock');
        $this->get(route('store.product', $this->vps))->assertOk()->assertSee('VPS is out of stock')->assertDontSee('Add to cart');
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY])->assertSessionHasErrors();
    }

    public function test_stock_running_out_after_it_was_added_blocks_checkout(): void
    {
        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY]);
        $this->vps->update(['stock_quantity' => 0]);

        $this->get(self::CART)->assertSee('VPS is out of stock.');
        $this->post('/checkout')->assertRedirect(route('cart.index'));
        $this->assertSame(0, Service::count());
    }

    public function test_products_without_stock_control_are_unlimited(): void
    {
        $this->vps->update(['stock_control' => false, 'stock_quantity' => 0]);

        $this->post(route('cart.store', $this->vps), ['billing_cycle' => self::MONTHLY])->assertSessionHasNoErrors();
        $this->post('/checkout')->assertRedirect();
        $this->assertSame(0, $this->vps->refresh()->stock_quantity);
    }
}
