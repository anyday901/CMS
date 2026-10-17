<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Models\Activity;
use App\Models\Client;
use App\Models\LoginAttempt;
use App\Models\Order;
use App\Models\ScheduledTaskRun;
use App\Models\Transaction;
use App\Models\User;
use App\Support\SystemOverview;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_shows_the_whmcs_panels(): void
    {
        $this->travelTo(now()->setTime(14, 0));
        $this->actingAs(User::factory()->create(['name' => 'Sam Staff']));
        $client = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        Order::create(['client_id' => $client->id]);
        Order::create(['client_id' => $client->id, 'status' => OrderStatus::Active]);
        Transaction::create(['client_id' => $client->id, 'gateway' => 'stripe', 'currency' => 'USD', 'amount' => 2500]);
        Transaction::create(['client_id' => $client->id, 'gateway' => 'stripe', 'currency' => 'USD', 'amount' => 900, 'pending' => true]);
        Transaction::create(['client_id' => $client->id, 'gateway' => 'stripe', 'currency' => 'EUR', 'amount' => 700]);
        Transaction::create(['client_id' => $client->id, 'gateway' => 'stripe', 'currency' => 'USD', 'amount' => 1000])->forceFill(['created_at' => now()->subYears(2)])->save();
        Activity::create(['actor_type' => 'system', 'actor_name' => 'System', 'description' => 'Generated invoice #7 for $10.00']);
        Activity::create(['actor_type' => 'system', 'actor_name' => 'System', 'description' => 'Suspended vps1 (overdue)']);
        ScheduledTaskRun::create(['task' => 'billing:run', 'started_at' => now()->setTime(0, 5), 'status' => ScheduledTaskRun::SUCCEEDED]);
        LoginAttempt::create(['area' => LoginAttempt::PORTAL, 'email' => 'ada@example.com', 'account_type' => $client->getMorphClass(), 'account_id' => $client->id, 'client_id' => $client->id, 'outcome' => LoginAttempt::SUCCESS, 'created_at' => now()->subMinutes(5)]);

        $response = $this->get('/admin')->assertOk();

        $response->assertSeeInOrder(['Shortcuts', 'System Information', 'Advanced Search', 'Staff Online', 'Sam Staff']);
        $response->assertSeeInOrder(['Pending Orders', 'Tickets Waiting', 'Pending Cancellations', 'Pending Module Actions']);
        $response->assertSeeInOrder(['System Overview', 'Billing', 'Payments to review', 'Client Activity', 'Automation Overview', 'Activity']);
        $response->assertViewHas('pendingOrderCount', 1);
        // The pending payment isn't income yet, a payment in another currency can't be added in, and the old one only counts towards all time.
        $response->assertViewHas('incomeToday', 2500)->assertViewHas('incomeAllTime', 3500);
        $response->assertViewHas('paymentsToReviewCount', 1)->assertSee('1</strong> payment waiting for review', false);
        $response->assertViewHas('clientsSignedIn', 1)->assertSee('Ada Lovelace');
        $response->assertSee('Today at 12:05 AM')->assertSee('Generated invoice #7 for $10.00');

        $automation = collect($response->viewData('automation'))->keyBy('label');
        $this->assertSame(1, $automation['Invoices Created']['today']);
        $this->assertSame(1, $automation['Overdue Suspensions']['today']);
        $this->assertSame(0, $automation['Overdue Reminders']['today']);
    }

    public function test_the_chart_periods(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin?period=year')->assertOk()->assertViewHas('overview', fn (SystemOverview $overview) => count($overview->buckets) === 12);
        $this->get('/admin?period=30days')->assertOk()->assertViewHas('overview', fn (SystemOverview $overview) => count($overview->buckets) === 30);
        $this->get('/admin?period=nonsense')->assertOk()->assertViewHas('overview', fn (SystemOverview $overview) => $overview->period === SystemOverview::TODAY);
    }

    public function test_orders_and_income_land_in_the_right_bucket(): void
    {
        $now = CarbonImmutable::parse('2026-10-17 15:30:00');
        $this->travelTo($now);
        $client = Client::factory()->create();
        $order = Order::create(['client_id' => $client->id]);
        $order->forceFill(['created_at' => $now->setTime(9, 15)])->save();
        Transaction::create(['client_id' => $client->id, 'gateway' => 'stripe', 'currency' => 'USD', 'amount' => 1200])->forceFill(['created_at' => $now->setTime(9, 45)])->save();
        Transaction::create(['client_id' => $client->id, 'gateway' => 'credit', 'currency' => 'USD', 'amount' => 500])->forceFill(['created_at' => $now->setTime(9, 45)])->save();

        $overview = new SystemOverview(SystemOverview::TODAY, $now);

        $this->assertCount(16, $overview->buckets);
        $this->assertSame(1, $overview->newOrders()[9]);
        $this->assertSame(1200, $overview->income()[9]);
        $this->assertSame(1200, array_sum($overview->income()));
    }

    public function test_staff_without_settings_access_dont_see_the_activity_log(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]));
        Activity::create(['actor_type' => 'system', 'actor_name' => 'System', 'description' => 'Secret thing']);

        $this->get('/admin')->assertOk()->assertSee("Your role can't see the activity log.", false)->assertDontSee('Secret thing');
    }
}
