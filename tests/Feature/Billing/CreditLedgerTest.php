<?php

namespace Tests\Feature\Billing;

use App\Billing\CreditApplier;
use App\Billing\PaymentRecorder;
use App\Billing\RefundService;
use App\Models\Client;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const GOODWILL = 'Outage goodwill';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create();
    }

    private function invoice(int $amount): Invoice
    {
        $invoice = Invoice::create(['client_id' => $this->client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $amount]);

        return $invoice->recalculate();
    }

    public function test_every_balance_change_has_a_history_line(): void
    {
        $first = $this->invoice(1000);
        app(PaymentRecorder::class)->record($first, 1500, 'cash'); // 500 over
        $payment = $first->transactions()->sole();
        app(RefundService::class)->refund($payment, 200, 'credit');
        app(CreditApplier::class)->apply($this->invoice(600));

        $entries = CreditEntry::where('client_id', $this->client->id)->orderBy('id')->get();

        $this->assertSame([500, 200, -600], $entries->pluck('amount')->all());
        $this->assertSame([500, 700, 100], $entries->pluck('balance_after')->all());
        $this->assertSame("Overpayment on invoice #{$first->number}", $entries[0]->description);
        $this->assertSame(100, $this->client->fresh()->credit_balance);
    }

    public function test_staff_add_and_remove_credit_with_a_reason(): void
    {
        $staff = User::factory()->create(['name' => 'Alex']);
        $this->actingAs($staff, 'web');

        $this->post("/admin/clients/{$this->client->id}/credit", ['direction' => 'add', 'amount' => '25.00', 'reason' => self::GOODWILL])
            ->assertSessionDoesntHaveErrors();
        $this->post("/admin/clients/{$this->client->id}/credit", ['direction' => 'remove', 'amount' => '5', 'reason' => 'Correction'])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(2000, $this->client->fresh()->credit_balance);
        $entry = CreditEntry::where('description', self::GOODWILL)->sole();
        $this->assertSame($staff->id, $entry->user_id);

        $this->get("/admin/clients/{$this->client->id}")->assertSee(self::GOODWILL)->assertSee('Alex')->assertSee('$20.00 available');
    }

    public function test_credit_cannot_go_below_zero(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->post("/admin/clients/{$this->client->id}/credit", ['direction' => 'remove', 'amount' => '1', 'reason' => 'Oops'])
            ->assertSessionHasErrors('amount');
        $this->post("/admin/clients/{$this->client->id}/credit", ['direction' => 'add', 'amount' => '5'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, $this->client->fresh()->credit_balance);
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_support_staff_cannot_adjust_credit(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']), 'web');

        $this->post("/admin/clients/{$this->client->id}/credit", ['direction' => 'add', 'amount' => '5', 'reason' => 'x'])->assertForbidden();
        $this->get("/admin/clients/{$this->client->id}")->assertDontSee('Adjust credit');
    }

    public function test_clients_see_their_credit_history(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->post("/admin/clients/{$this->client->id}/credit", ['direction' => 'add', 'amount' => '10', 'reason' => 'Referral bonus']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->client, 'client');
        $this->get('/portal')->assertSee('Account credit')->assertSee('Referral bonus')->assertSee('+$10.00');
    }
}
