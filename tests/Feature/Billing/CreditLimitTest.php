<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceGenerator;
use App\Billing\OverdueProcessor;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditLimitTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENTS = '/admin/clients';

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.suspend_after_days' => 3, 'billing.terminate_after_days' => 30, 'billing.credit_limit' => null]);
    }

    /** A service with an overdue $10.00 renewal invoice. */
    private function overdueService(Client $client): Service
    {
        $service = Service::factory()->create(['client_id' => $client->id, 'recurring_amount' => 1000]);
        app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-10-25'));

        return $service;
    }

    private function runOverdue(string $date = '2026-11-04'): array
    {
        return app(OverdueProcessor::class)->run(CarbonImmutable::parse($date));
    }

    public function test_client_within_their_limit_is_not_suspended(): void
    {
        $within = $this->overdueService(Client::factory()->create(['credit_limit' => 1000]));
        $over = $this->overdueService(Client::factory()->create(['credit_limit' => 999]));
        $none = $this->overdueService(Client::factory()->create(['credit_limit' => 0]));

        $this->assertSame(['suspended' => 2, 'terminated' => 0], $this->runOverdue());
        $this->assertSame(ServiceStatus::Active, $within->refresh()->status);
        $this->assertSame(ServiceStatus::Suspended, $over->refresh()->status);
        $this->assertSame(ServiceStatus::Suspended, $none->refresh()->status);

        // Nor terminated while within it.
        $this->runOverdue('2026-12-01');
        $this->assertSame(ServiceStatus::Active, $within->refresh()->status);
    }

    public function test_running_up_more_than_the_limit_lets_suspension_kick_in(): void
    {
        $client = Client::factory()->create(['credit_limit' => 1500]);
        $service = $this->overdueService($client);
        $this->assertSame(['suspended' => 0, 'terminated' => 0], $this->runOverdue());

        // A second unpaid invoice takes the client to $20.00, over the $15.00 limit.
        Service::factory()->create(['client_id' => $client->id, 'recurring_amount' => 1000, 'next_due_date' => '2026-11-05']);
        app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-11-05'));
        $this->assertSame(2000, $client->unpaidBalance());

        $this->assertSame(['suspended' => 1, 'terminated' => 0], $this->runOverdue('2026-11-05'));
        $this->assertSame(ServiceStatus::Suspended, $service->refresh()->status);
    }

    public function test_limit_falls_back_to_the_group_then_the_default(): void
    {
        $group = ClientGroup::create(['name' => 'Trusted', 'colour' => '#16a34a', 'credit_limit' => 5000]);
        $client = Client::factory()->create(['client_group_id' => $group->id]);
        $this->assertSame([5000, Client::CREDIT_LIMIT_GROUP], [$client->creditLimit(), $client->creditLimitSource()]);

        $client->credit_limit = 0;
        $this->assertSame([0, Client::CREDIT_LIMIT_CLIENT], [$client->creditLimit(), $client->creditLimitSource()]);

        config(['billing.credit_limit' => '25.00']);
        $plain = Client::factory()->create();
        $this->assertSame([2500, Client::CREDIT_LIMIT_DEFAULT], [$plain->creditLimit(), $plain->creditLimitSource()]);

        config(['billing.credit_limit' => null]);
        $this->assertNull($plain->creditLimit());
        $this->assertFalse($plain->withinCreditLimit());
    }

    public function test_default_limit_is_set_under_automation(): void
    {
        $this->actingAs(User::factory()->create());
        $form = [
            'run_time' => '00:05', 'invoice_days_before_due' => '7', 'late_fee_type' => 'fixed', 'suspend_after_days' => '3', 'terminate_after_days' => '30', 'overage_due_days' => '7',
        ];

        $this->put('/admin/settings/automation', $form + ['credit_limit' => 'lots'])->assertSessionHasErrors('credit_limit');
        $this->put('/admin/settings/automation', $form + ['credit_limit' => '100'])->assertSessionHasNoErrors();
        Settings::apply();
        $this->assertSame('100.00', config('billing.credit_limit'));
        $this->get('/admin/settings/automation')->assertSee('value="100.00"', false);

        $within = $this->overdueService(Client::factory()->create());
        $this->assertSame(['suspended' => 0, 'terminated' => 0], $this->runOverdue());
        $this->assertSame(ServiceStatus::Active, $within->refresh()->status);
    }

    public function test_staff_set_the_limit_and_see_how_much_is_used(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->overdueService($client);
        $details = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => $client->email, 'status' => 'active'];

        $this->get(self::CLIENTS."/{$client->id}/edit")->assertOk()->assertSee('name="credit_limit"', false);
        $this->put(self::CLIENTS."/{$client->id}", $details + ['credit_limit' => '-5'])->assertSessionHasErrors('credit_limit');
        $this->put(self::CLIENTS."/{$client->id}", $details + ['credit_limit' => '40'])->assertRedirect();
        $this->assertSame(4000, $client->fresh()->credit_limit);

        $this->get(self::CLIENTS."/{$client->id}")->assertSee('Credit limit')->assertSee('$10.00 of $40.00 used')->assertSee('25% of the credit limit used');

        $this->put(self::CLIENTS."/{$client->id}", $details + ['credit_limit' => '5'])->assertRedirect();
        $this->get(self::CLIENTS."/{$client->id}")->assertSee('$10.00 of $5.00 used')->assertSee('Over the limit');

        // Blank falls back to the group's, or none.
        $this->put(self::CLIENTS."/{$client->id}", $details + ['credit_limit' => ''])->assertRedirect();
        $this->assertNull($client->fresh()->credit_limit);
    }

    public function test_add_funds_and_mass_payment_invoices_do_not_use_up_the_limit(): void
    {
        $client = Client::factory()->create(['credit_limit' => 1000]);
        $this->overdueService($client);
        $funds = Invoice::create(['client_id' => $client->id, 'currency' => $client->currency, 'issue_date' => '2026-10-25', 'due_date' => '2026-10-25']);
        $funds->items()->create(['type' => InvoiceItem::TYPE_ADD_FUNDS, 'description' => 'Add funds', 'amount' => 5000, 'taxable' => false]);
        $funds->recalculate();

        $this->assertSame(1000, $client->unpaidBalance());
        $this->assertTrue($client->withinCreditLimit());
    }
}
