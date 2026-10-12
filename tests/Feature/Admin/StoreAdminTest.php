<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreAdminTest extends TestCase
{
    use RefreshDatabase;

    private const SMALL_VPS = 'Small VPS';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_staff_manage_product_groups(): void
    {
        $this->get(route('admin.product-groups.index'))->assertOk()->assertSee('No groups yet');
        $this->post(route('admin.product-groups.store'), ['name' => 'Web Hosting', 'visible' => '1'])->assertRedirect();

        $group = ProductGroup::sole();
        $this->assertSame('web-hosting', $group->slug);
        $this->assertTrue($group->visible);

        $this->post(route('admin.product-groups.store'), ['name' => 'Web hosting'])->assertSessionHasErrors('slug');
        $this->post(route('admin.product-groups.store'), ['name' => 'Order'])->assertSessionHasErrors('slug');

        $this->put(route('admin.product-groups.update', $group), ['name' => 'Hosting', 'slug' => 'hosting', 'visible' => '0'])->assertRedirect();
        $this->assertFalse($group->refresh()->visible);

        $product = Product::factory()->create(['product_group_id' => $group->id]);
        $this->delete(route('admin.product-groups.destroy', $group))->assertRedirect();
        $this->assertNull($product->refresh()->product_group_id);
    }

    public function test_products_get_a_group_add_ons_and_upgrades(): void
    {
        $group = ProductGroup::create(['name' => 'VPS', 'slug' => 'vps']);
        $addon = Product::factory()->create(['name' => 'Backups', 'is_addon' => true]);
        $bigger = Product::factory()->create(['name' => 'Bigger']);

        $this->post(route('admin.products.store'), [
            'name' => self::SMALL_VPS,
            'product_group_id' => $group->id,
            'sort_order' => 3,
            'active' => '1',
            'addons' => [$addon->id],
            'upgrades' => [$bigger->id],
            'prices' => ['monthly' => ['price' => '5.00']],
        ])->assertRedirect();

        $product = Product::where('name', self::SMALL_VPS)->sole();
        $this->assertSame($group->id, $product->product_group_id);
        $this->assertSame(3, $product->sort_order);
        $this->assertSame([$addon->id], $product->addons()->pluck('products.id')->all());
        $this->assertSame([$bigger->id], $product->upgrades()->pluck('products.id')->all());
        $this->get(route('admin.products.edit', $product))->assertOk()->assertSee('Add-ons offered with this product');

        // Only add-ons can be add-ons, and an add-on has none of its own.
        $this->put(route('admin.products.update', $product), ['name' => self::SMALL_VPS, 'addons' => [$bigger->id]])->assertSessionHasErrors('addons.0');
        $this->put(route('admin.products.update', $product), ['name' => self::SMALL_VPS, 'is_addon' => '1', 'addons' => [$addon->id]])->assertRedirect();
        $this->assertSame([], $product->addons()->pluck('products.id')->all());
    }

    public function test_staff_manage_promotion_codes(): void
    {
        $product = Product::factory()->create();
        $this->get(route('admin.promotions.index'))->assertOk();

        $this->post(route('admin.promotions.store'), [
            'code' => 'spring-25',
            'type' => Promotion::PERCENT,
            'value' => '25',
            'recurring' => '1',
            'product_ids' => [$product->id],
            'expires_on' => '2027-01-31',
            'max_uses' => 100,
            'active' => '1',
        ])->assertRedirect();

        $promotion = Promotion::sole();
        $this->assertSame('SPRING-25', $promotion->code);
        $this->assertSame(2500, $promotion->value);
        $this->assertTrue($promotion->recurring);
        $this->assertSame([$product->id], $promotion->product_ids);
        $this->assertSame('25% off every payment', $promotion->summary());
        $this->get(route('admin.promotions.index'))->assertSee('SPRING-25')->assertSee('0 of 100');

        $this->post(route('admin.promotions.store'), ['code' => 'SPRING-25', 'type' => Promotion::FIXED, 'value' => '5'])->assertSessionHasErrors('code');
        $this->post(route('admin.promotions.store'), ['code' => 'BAD CODE', 'type' => Promotion::FIXED, 'value' => '5'])->assertSessionHasErrors('code');
        $this->post(route('admin.promotions.store'), ['code' => 'TOOMUCH', 'type' => Promotion::PERCENT, 'value' => '101'])->assertSessionHasErrors('value');

        $this->put(route('admin.promotions.update', $promotion), ['code' => 'SPRING-25', 'type' => Promotion::FIXED, 'value' => '5', 'active' => '0'])->assertRedirect();
        $this->assertSame(['fixed', 500, false, null], [$promotion->refresh()->type, $promotion->value, $promotion->active, $promotion->product_ids]);

        $promotion->forceFill(['uses' => 1])->save();
        $this->delete(route('admin.promotions.destroy', $promotion))->assertStatus(422);
        $promotion->forceFill(['uses' => 0])->save();
        $this->delete(route('admin.promotions.destroy', $promotion))->assertRedirect();
        $this->assertSame(0, Promotion::count());
    }

    public function test_support_staff_cannot_manage_the_store(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']));

        $this->get(route('admin.promotions.index'))->assertForbidden();
        $this->get(route('admin.product-groups.index'))->assertForbidden();
    }
}
