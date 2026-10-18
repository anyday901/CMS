<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceGenerator;
use App\Billing\LateFeeProcessor;
use App\Billing\OverdueProcessor;
use App\Billing\PaymentRecorder;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientGroupSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const GROUPS = '/admin/client-groups';

    private const MONTHLY = 'monthly';

    private const RENEWAL_RUN = '2026-10-25';

    private function group(array $attributes = []): ClientGroup
    {
        return ClientGroup::create($attributes + ['name' => 'Resellers', 'colour' => '#7c3aed']);
    }

    private function clientIn(ClientGroup $group): Client
    {
        return Client::factory()->create(['client_group_id' => $group->id]);
    }

    private function renew(string $today = self::RENEWAL_RUN)
    {
        return app(InvoiceGenerator::class)->generate(CarbonImmutable::parse($today));
    }

    public function test_staff_set_a_groups_discount_and_settings(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(self::GROUPS, ['name' => 'Resellers', 'colour' => '#7c3aed', 'discount_percent' => '120'])->assertSessionHasErrors('discount_percent');
        $this->post(self::GROUPS, [
            'name' => 'Resellers', 'colour' => '#7c3aed', 'discount_percent' => '12.5', 'discount_renewals' => '1',
            'exempt_late_fees' => '1', 'exempt_suspension' => '0', 'separate_invoices' => '1', 'credit_limit' => '250',
        ])->assertRedirect(self::GROUPS)->assertSessionHasNoErrors();

        $group = ClientGroup::sole();
        $this->assertSame([1250, true, true, false, true, 25000], [
            $group->discount_percent, $group->discount_renewals, $group->exempt_late_fees, $group->exempt_suspension, $group->separate_invoices, $group->credit_limit,
        ]);
        $this->get(self::GROUPS)->assertSee('12.5% off new orders and renewals, no late fees, separate invoices, $250.00 credit limit');
        $this->get(self::GROUPS."/{$group->id}/edit")->assertOk()->assertSee('value="12.50"', false)->assertSee('value="250.00"', false);

        // Blank means no discount and no limit of the group's own.
        $this->put(self::GROUPS."/{$group->id}", ['name' => 'Resellers', 'colour' => '#7c3aed', 'discount_percent' => '', 'credit_limit' => ''])->assertRedirect(self::GROUPS);
        $this->assertSame([0, false, null], [$group->fresh()->discount_percent, $group->fresh()->separate_invoices, $group->fresh()->credit_limit]);
    }

    public function test_discount_comes_off_new_orders_after_a_promotion_code(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-11'));
        $vps = Product::factory()->create(['name' => 'VPS']);
        $vps->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 1000, 'setup_fee' => 500]);
        Promotion::create(['code' => 'HALF', 'type' => Promotion::PERCENT, 'value' => 5000]);
        $client = $this->clientIn($this->group(['discount_percent' => 1000]));
        $this->actingAs($client, 'client');

        $this->post(route('cart.store', $vps), ['billing_cycle' => self::MONTHLY]);
        $this->post('/cart/promotion', ['code' => 'HALF'])->assertSessionHasNoErrors();
        // 10.00 - 5.00 HALF - 0.50 (10% of what's left) + 5.00 setup, which isn't discounted.
        $this->get('/cart')->assertSee('Your 10% account discount')->assertSee('-$0.50')->assertSee('$9.50');
        $this->post('/checkout')->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame(950, $invoice->total);
        $line = $invoice->items()->where('type', InvoiceItem::TYPE_GROUP_DISCOUNT)->sole();
        $this->assertSame([-50, 'Account discount (10%): VPS'], [$line->amount, $line->description]);

        // The group doesn't discount renewals, so the next one is full price.
        app(PaymentRecorder::class)->record($invoice, 950, 'manual');
        $this->assertSame(1000, $this->renew('2026-11-11')->sole()->total);
    }

    public function test_discount_comes_off_renewals_when_the_group_says_so(): void
    {
        $group = $this->group(['discount_percent' => 1000, 'discount_renewals' => true]);
        $service = Service::factory()->create(['client_id' => $this->clientIn($group)->id, 'recurring_amount' => 1999]);

        $invoice = $this->renew()->sole();

        $this->assertSame(1799, $invoice->total);
        $this->assertSame([-200], $invoice->items()->where('type', InvoiceItem::TYPE_GROUP_DISCOUNT)->where('service_id', $service->id)->pluck('amount')->all());

        // Without it, renewals are full price.
        $group->update(['discount_renewals' => false]);
        $other = Service::factory()->create(['client_id' => $this->clientIn($group)->id, 'recurring_amount' => 1999]);
        $this->assertSame(1999, $this->renew()->sole()->total);
        $this->assertSame($other->id, Invoice::latest('id')->first()->items()->sole()->service_id);
    }

    public function test_separate_invoice_for_each_service(): void
    {
        $client = $this->clientIn($this->group(['separate_invoices' => true]));
        Service::factory()->count(2)->create(['client_id' => $client->id]);
        $together = Client::factory()->create();
        Service::factory()->count(2)->create(['client_id' => $together->id]);

        $this->assertCount(3, $this->renew());
        $this->assertSame([1, 1], $client->invoices()->withCount('items')->pluck('items_count')->all());
        $this->assertSame([2], $together->invoices()->withCount('items')->pluck('items_count')->all());
        $this->assertCount(0, $this->renew());
    }

    public function test_group_can_be_exempt_from_late_fees(): void
    {
        config(['billing.late_fee_after_days' => 5, 'billing.late_fee_type' => 'fixed', 'billing.late_fee_amount' => '5.00']);
        $exempt = Service::factory()->create(['client_id' => $this->clientIn($this->group(['exempt_late_fees' => true]))->id]);
        $charged = Service::factory()->create();
        $this->renew();

        $this->assertSame(1, app(LateFeeProcessor::class)->run(CarbonImmutable::parse('2026-11-06')));
        $this->assertFalse(InvoiceItem::where('type', InvoiceItem::TYPE_LATE_FEE)->whereRelation('invoice', 'client_id', $exempt->client_id)->exists());
        $this->assertTrue(InvoiceItem::where('type', InvoiceItem::TYPE_LATE_FEE)->whereRelation('invoice', 'client_id', $charged->client_id)->exists());
    }

    public function test_group_can_be_exempt_from_overdue_suspension_and_termination(): void
    {
        config(['billing.suspend_after_days' => 3, 'billing.terminate_after_days' => 30]);
        $exempt = Service::factory()->create(['client_id' => $this->clientIn($this->group(['exempt_suspension' => true]))->id]);
        $suspended = Service::factory()->create();
        $this->renew();

        $this->assertSame(['suspended' => 1, 'terminated' => 0], app(OverdueProcessor::class)->run(CarbonImmutable::parse('2026-11-04')));
        $this->assertSame(['suspended' => 0, 'terminated' => 1], app(OverdueProcessor::class)->run(CarbonImmutable::parse('2026-12-01')));
        $this->assertSame(ServiceStatus::Active, $exempt->refresh()->status);
        $this->assertSame(ServiceStatus::Terminated, $suspended->refresh()->status);
    }
}
