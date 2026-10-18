<?php

namespace Tests\Feature\Billing;

use App\Billing\CreditApplier;
use App\Billing\InvoiceMerger;
use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\StaffRole;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\TaxRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class InvoiceMergeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/invoices/merge';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        TaxRule::create(['name' => 'Sales tax', 'rate' => 1000, 'country' => 'US']);
        $this->client = Client::factory()->create(['country' => 'US']);
    }

    /** @param  list<array{0: int, 1: bool}>  $lines  amount and taxable */
    private function invoice(array $lines, ?Client $client = null, string $due = '+7 days'): Invoice
    {
        $invoice = Invoice::create(['client_id' => ($client ?? $this->client)->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()->modify($due), 'notes' => 'Thanks']);

        foreach ($lines as [$amount, $taxable]) {
            $invoice->items()->create(['description' => 'Line', 'amount' => $amount, 'taxable' => $taxable]);
        }

        return $invoice->recalculate();
    }

    public function test_staff_merge_a_clients_unpaid_invoices_from_the_client_page(): void
    {
        $first = $this->invoice([[1000, true]], due: '+10 days');
        $second = $this->invoice([[2000, true], [500, false]], due: '+3 days');
        $service = Service::factory()->for($this->client)->pending()->create();
        $first->items()->create(['service_id' => $service->id, 'type' => InvoiceItem::TYPE_SERVICE, 'description' => 'Hosting', 'amount' => 300, 'taxable' => true, 'period_start' => today()]);
        $first->recalculate();

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/clients/{$this->client->id}?tab=invoices")->assertSee('Merge selected')->assertSee('form="merge-invoices"', false);
        $response = $this->post(self::URL, ['invoices' => [$second->id, $first->id]]);

        // The oldest is kept, with every line, one tax figure and the earliest due date.
        $response->assertRedirect("/admin/invoices/{$first->id}");
        $first->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $first->status);
        $this->assertSame(4, $first->items()->count());
        $this->assertSame(3800, $first->subtotal);
        $this->assertSame(330, $first->tax);
        $this->assertSame(4130, $first->total);
        $this->assertSame(today()->addDays(3)->toDateString(), $first->due_date->toDateString());

        $second->refresh();
        $this->assertSame(InvoiceStatus::Cancelled, $second->status);
        $this->assertSame("Thanks\n\nMerged into invoice #{$first->number}.", $second->notes);
        $this->assertSame(2, $second->items()->count());
        $this->get("/admin/invoices/{$second->id}")->assertSee("Merged into invoice #{$first->number}.");

        // The service waiting on the merged invoice still activates when it's paid.
        $this->assertSame(ServiceStatus::Pending, $service->refresh()->status);
        app(PaymentRecorder::class)->record($first, 4130, 'paypal');
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
    }

    public function test_payments_and_credit_on_the_merged_invoices_carry_over(): void
    {
        $first = $this->invoice([[1000, true]]);
        $second = $this->invoice([[2000, true]]);
        $third = $this->invoice([[500, false]]);
        $this->client->forceFill(['credit_balance' => 200])->save();
        app(CreditApplier::class)->apply($second);
        app(PaymentRecorder::class)->record($third, 300, 'bank_transfer');

        $merged = app(InvoiceMerger::class)->merge([$first->id, $second->id, $third->id]);

        // The oldest with a payment is kept, and the third's payment moves over through credit.
        $this->assertTrue($merged->is($second));
        $this->assertSame(3800, $merged->total);
        $this->assertSame(500, $merged->amountPaid());
        $this->assertSame(3300, $merged->balance());
        $this->assertSame(0, $this->client->refresh()->credit_balance);
        $this->assertSame(InvoiceStatus::Cancelled, $third->refresh()->status);
        $this->assertSame(0, $third->amountPaid());
        // The bank transfer stays on the invoice it was paid on, refunded to credit.
        $this->assertSame(['bank_transfer', 'credit'], $third->transactions()->orderBy('id')->pluck('gateway')->all());
    }

    public function test_only_matching_unpaid_invoices_can_be_merged(): void
    {
        $first = $this->invoice([[1000, true]]);
        $paid = $this->invoice([[1000, true]]);
        app(PaymentRecorder::class)->record($paid, 1100, 'paypal');
        $otherClient = $this->invoice([[1000, true]], Client::factory()->create(['country' => 'US']));
        $euros = $this->invoice([[1000, true]]);
        $euros->forceFill(['currency' => 'EUR'])->save();
        $untaxed = $this->invoice([[1000, true]]);
        $untaxed->forceFill(['tax_rate' => 0, 'tax_name' => null])->save();

        $errors = [
            [[$first->id], 'Choose at least two invoices to merge.'],
            [[$first->id, $paid->id], 'Only unpaid invoices can be merged.'],
            [[$first->id, $otherClient->id], 'Only invoices of the same client can be merged.'],
            [[$first->id, $euros->id], 'Only invoices in the same currency can be merged.'],
            [[$first->id, $untaxed->id], 'These invoices have different tax rates, so they can\'t be merged.'],
        ];

        foreach ($errors as [$ids, $message]) {
            try {
                app(InvoiceMerger::class)->merge($ids);
                $this->fail("Merged {$message}");
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertSame(InvoiceStatus::Unpaid, $first->refresh()->status);
        $this->assertSame(1, $first->items()->count());
    }

    public function test_merging_needs_the_billing_permission(): void
    {
        $first = $this->invoice([[1000, true]]);
        $second = $this->invoice([[1000, true]]);

        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');
        $this->get('/admin/invoices')->assertOk()->assertDontSee('Merge selected');
        $this->post(self::URL, ['invoices' => [$first->id, $second->id]])->assertForbidden();

        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin/invoices')->assertSee('Merge selected');
        $this->post(self::URL, ['invoices' => [$first->id]])->assertSessionHasErrors('invoices');
        $this->post(self::URL, ['invoices' => [$first->id, $second->id]])->assertRedirect("/admin/invoices/{$first->id}");
    }
}
