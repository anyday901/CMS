<?php

namespace Tests\Feature;

use App\Billing\InvoiceGenerator;
use App\Billing\OrderChoices;
use App\Billing\OrderService;
use App\Enums\BillingCycle;
use App\Models\Client;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

class ConfigurableOptionsTest extends TestCase
{
    use RefreshDatabase;

    private const MONTHLY = 'monthly';

    private const BACKUPS = 'Backups';

    private const EXTRA_IPS = 'Extra IPs';

    private const ANNUALLY = 'annually';

    private Product $product;

    private ProductOption $disk;

    private ProductOption $backups;

    private ProductOption $ips;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->product = Product::factory()->create(['name' => 'VPS']);
        $this->product->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'USD', 'price' => 1000, 'setup_fee' => 500]);

        $this->disk = $this->product->options()->create(['name' => 'Disk', 'type' => ProductOption::DROPDOWN]);
        $this->disk->choices()->create(['name' => '25 GB', 'sort_order' => 0]);
        $this->disk->choices()->create(['name' => '50 GB', 'prices' => [self::MONTHLY => 300], 'setup_fee' => 200, 'sort_order' => 1]);
        $this->backups = $this->product->options()->create(['name' => self::BACKUPS, 'type' => ProductOption::CHECKBOX, 'sort_order' => 1]);
        $this->backups->choices()->create(['name' => self::BACKUPS, 'prices' => [self::MONTHLY => 150]]);
        $this->ips = $this->product->options()->create(['name' => self::EXTRA_IPS, 'type' => ProductOption::QUANTITY, 'max_quantity' => 4, 'sort_order' => 2]);
        $this->ips->choices()->create(['name' => self::EXTRA_IPS, 'prices' => [self::MONTHLY => 200]]);
    }

    private function order(array $options, ?Client $client = null): array
    {
        return app(OrderService::class)->create($client ?? Client::factory()->create(), $this->product, BillingCycle::Monthly, CarbonImmutable::parse('2026-10-11'), 'host1', true, new OrderChoices($options));
    }

    public function test_chosen_options_are_priced_into_the_service_and_its_invoices(): void
    {
        $fifty = $this->disk->choices()->where('name', '50 GB')->value('id');
        ['service' => $service, 'invoice' => $invoice] = $this->order([$this->disk->id => $fifty, $this->backups->id => '1', $this->ips->id => '2']);

        // 10.00 + 3.00 + 1.50 + 2 x 2.00
        $this->assertSame(1850, $service->recurring_amount);
        $this->assertSame('Disk: 50 GB, Backups, Extra IPs: 2', $service->optionSummary());
        $lines = $invoice->items()->orderBy('id')->get();
        $this->assertSame([1850, 700], $lines->pluck('amount')->all());
        $this->assertStringEndsWith('(10/11/2026 - 11/10/2026), Disk: 50 GB, Backups, Extra IPs: 2', $lines[0]->description);

        // Renewals use the same amount and description.
        $service->update(['status' => 'active', 'next_due_date' => '2026-11-11']);
        config(['billing.invoice_days_before_due' => 14]);
        app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-11-01'));
        $renewal = InvoiceItem::whereDate('period_start', '2026-11-11')->sole();
        $this->assertSame(1850, $renewal->amount);
        $this->assertStringContainsString('Disk: 50 GB, Backups, Extra IPs: 2', $renewal->description);
    }

    public function test_defaults_and_bad_values(): void
    {
        ['service' => $service] = $this->order([]);
        $this->assertSame(1000, $service->recurring_amount);
        $this->assertSame('Disk: 25 GB', $service->optionSummary());

        foreach ([[$this->ips->id => '5'], [$this->ips->id => '-1'], [$this->disk->id => '999999']] as $bad) {
            try {
                $this->order($bad);
                $this->fail('Expected the order to be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_priced_options_are_refused_for_clients_in_another_currency(): void
    {
        $client = Client::factory()->create(['currency' => 'EUR']);
        $this->product->prices()->create(['billing_cycle' => self::MONTHLY, 'currency' => 'EUR', 'price' => 900]);

        $this->expectException(InvalidArgumentException::class);
        $this->order([$this->backups->id => '1'], $client);
    }

    public function test_staff_add_options_and_set_prices(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $product = Product::factory()->create();
        $product->prices()->create(['billing_cycle' => self::ANNUALLY, 'currency' => 'USD', 'price' => 10000]);

        $this->post("/admin/products/{$product->id}/options", ['name' => 'RAM', 'type' => 'dropdown'])->assertRedirect();
        $option = ProductOption::where('product_id', $product->id)->sole();
        $this->get("/admin/product-options/{$option->id}/edit")->assertOk()->assertSee('Annually');

        $this->put("/admin/product-options/{$option->id}", ['name' => 'RAM', 'choices' => [
            ['id' => '', 'name' => '1 GB', 'prices' => [self::ANNUALLY => '']],
            ['id' => '', 'name' => '2 GB', 'setup_fee' => '5', 'prices' => [self::ANNUALLY => '24.00']],
            ['id' => '', 'name' => ''],
        ]])->assertRedirect("/admin/products/{$product->id}/options");

        $choices = $option->choices()->get();
        $this->assertSame(['1 GB', '2 GB'], $choices->pluck('name')->all());
        $this->assertSame([2400, 500], [$choices[1]->priceFor(BillingCycle::Annually), $choices[1]->setup_fee]);

        $this->post("/admin/products/{$product->id}/options", ['name' => self::BACKUPS, 'type' => 'checkbox']);
        $checkbox = $product->options()->where('name', self::BACKUPS)->sole();
        $this->assertSame([self::BACKUPS], $checkbox->choices()->pluck('name')->all());
    }

    public function test_options_in_use_cannot_be_deleted(): void
    {
        $this->order([$this->backups->id => '1']);
        $this->actingAs(User::factory()->create(), 'web');

        $this->delete("/admin/product-options/{$this->backups->id}")->assertSessionHasErrors('option');
        $this->delete("/admin/product-options/{$this->ips->id}")->assertRedirect();
        $this->assertModelMissing($this->ips);
    }

    public function test_staff_add_a_service_with_options_and_change_them_later(): void
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->create(), 'web');

        $this->get("/admin/clients/{$client->id}/services/create")->assertOk()->assertSee(self::EXTRA_IPS);
        $this->post("/admin/clients/{$client->id}/services", [
            'product_id' => $this->product->id, 'billing_cycle' => self::MONTHLY, 'start_date' => '2026-10-11',
            'invoice' => '0', 'options' => [$this->backups->id => '1', $this->ips->id => '1'],
        ])->assertRedirect();
        $service = Service::sole();
        $this->assertSame(1350, $service->recurring_amount);

        $this->get("/admin/services/{$service->id}")->assertOk()->assertSee('Change options and fields');
        $this->put("/admin/services/{$service->id}", ['options' => [$this->backups->id => '0', $this->ips->id => '3']])->assertRedirect();
        $this->assertSame(1600, $service->fresh()->recurring_amount);
        $this->assertSame('Disk: 25 GB, Extra IPs: 3', $service->fresh()->optionSummary());

        $this->put("/admin/services/{$service->id}", ['options' => [$this->ips->id => '9']])->assertSessionHasErrors('options');
    }

    public function test_changes_use_the_price_each_option_was_billed_at(): void
    {
        ['service' => $service] = $this->order([$this->backups->id => '1', $this->ips->id => '1']);
        $this->assertSame(1350, $service->recurring_amount);

        // Prices go up after the order.
        $this->backups->choices()->first()->update(['prices' => [self::MONTHLY => 300]]);
        $this->ips->choices()->first()->update(['prices' => [self::MONTHLY => 500]]);
        $this->actingAs(User::factory()->create(), 'web');

        // Removing backups takes off the 1.50 it was billed at, and more IPs keep the 2.00 unit price they were billed at.
        $this->put("/admin/services/{$service->id}", ['options' => [$this->backups->id => '0', $this->ips->id => '2']])->assertRedirect();
        $this->assertSame(1350 - 150 + 200, $service->fresh()->recurring_amount);
        $this->assertSame(200, $service->fresh()->options->firstWhere('product_option_id', $this->ips->id)->unit_price);
    }
}
