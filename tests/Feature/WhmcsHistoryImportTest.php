<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Import\Whmcs\WhmcsImporter;
use App\Models\Activity;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\CreditEntry;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WhmcsDatabase;
use Tests\TestCase;

class WhmcsHistoryImportTest extends TestCase
{
    private const JANE = 'jane@example.com';

    use RefreshDatabase;

    private ConnectionInterface $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 12:00:00');

        $this->whmcs = WhmcsDatabase::create();
        WhmcsDatabase::seed($this->whmcs);
        WhmcsDatabase::seedHistory($this->whmcs);
        $this->whmcs->table('tbladmins')->insert(['id' => 1, 'firstname' => 'Jane', 'lastname' => 'Admin', 'email' => self::JANE]);
    }

    public function test_promotions_and_orders_keep_their_numbers_and_links(): void
    {
        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $this->assertSame(['found' => 2, 'imported' => 2, 'skipped' => 0], $report->counts()['Promotions']);
        $this->assertSame(['found' => 3, 'imported' => 2, 'skipped' => 1], $report->counts()['Orders']);

        $spring = Promotion::where('code', 'SPRING20')->sole();
        $this->assertSame([Promotion::PERCENT, 2000, true, 14, true, true], [$spring->type, $spring->value, $spring->recurring, $spring->uses, $spring->once_per_client, $spring->active]);
        $this->assertSame([Service::find(40)->product_id], $spring->product_ids);
        $this->assertNull($spring->expires_on);
        $this->assertFalse(Promotion::where('code', 'FREESETUP')->sole()->active);
        $this->assertStringContainsString('FREESETUP', implode(' ', $report->notes()['Promotions']));

        $order = Order::findOrFail(900);
        $this->assertSame([7, 100, OrderStatus::Active, $spring->id, 'SPRING20', '198.51.100.7'], [$order->client_id, $order->invoice_id, $order->status, $order->promotion_id, $order->promotion_code, $order->ip_address]);
        $this->assertSame('2024-01-15 08:30:00', $order->created_at->toDateTimeString());
        $this->assertSame(OrderStatus::Fraud, Order::findOrFail(901)->status);
        $this->assertSame([900, $spring->id], [Service::find(40)->order_id, Service::find(40)->promotion_id]);
    }

    public function test_the_credit_log_comes_over_and_still_adds_up_to_the_balance(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $entries = CreditEntry::where('client_id', 7)->orderBy('id')->get();
        $this->assertSame([2000, -850, 100], $entries->pluck('amount')->all());
        $this->assertSame([2000, 1150, 1250], $entries->pluck('balance_after')->all());
        $this->assertSame(['Goodwill credit', 'Credit Applied to Invoice #100', 'Difference between the WHMCS credit log and balance'], $entries->pluck('description')->all());
        $this->assertSame(100, $entries[1]->invoice_id);
        $this->assertSame(1250, Client::find(7)->credit_balance);
    }

    public function test_cancellation_requests_are_carried_out_or_still_waiting_as_in_whmcs(): void
    {
        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $done = CancellationRequest::where('service_id', 41)->sole();
        $this->assertSame([CancellationRequest::END_OF_PERIOD, CancellationRequest::COMPLETED, 'Moving to a bigger plan', '2025-03-01'], [$done->type, $done->status, $done->reason, $done->cancel_on->toDateString()]);
        $this->assertSame('2025-02-20', Service::find(41)->terminated_at->toDateString());

        $waiting = CancellationRequest::where('service_id', 40)->sole();
        $this->assertSame([CancellationRequest::IMMEDIATE, CancellationRequest::PENDING, '2026-10-01'], [$waiting->type, $waiting->status, $waiting->cancel_on->toDateString()]);
        $this->assertStringContainsString('1 request(s) are for services WHMCS hadn\'t cancelled yet', implode(' ', $report->notes()['Cancellation requests']));
    }

    public function test_the_activity_log_keeps_who_did_what_and_when(): void
    {
        config(['activity.retention_days' => null]);
        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $this->assertSame(['found' => 4, 'imported' => 3, 'skipped' => 1], $report->counts()['Activity log']);
        $jane = User::where('email', self::JANE)->sole();

        $accepted = Activity::where('description', 'Order Accepted - Order ID: 900')->sole();
        $this->assertSame(['staff', $jane->id, 'jane', 7, '203.0.113.9', '2024-01-15 08:31:00'], [$accepted->actor_type, $accepted->actor_id, $accepted->actor_name, $accepted->client_id, $accepted->ip_address, $accepted->created_at->toDateTimeString()]);
        $this->assertSame(['client', 7], [Activity::where('description', 'Client Logged In')->sole()->actor_type, Activity::where('description', 'Client Logged In')->sole()->actor_id]);
        $this->assertSame(['system', 'System'], [Activity::where('description', 'Cron Job: Starting')->sole()->actor_type, Activity::where('description', 'Cron Job: Starting')->sole()->actor_name]);
    }

    public function test_imported_history_shows_up_in_reports(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        $this->actingAs(User::where('email', self::JANE)->sole(), 'web');

        $this->get('/admin/reports/promotion-usage?from=2024-01-01&to=2024-12-31')->assertOk()->assertSeeInOrder(['SPRING20', '20%, recurring', '1']);
        $this->get('/admin/reports/new-orders-and-clients?from=2024-01-01&to=2024-01-31')->assertOk()->assertSeeInOrder(['Jan 2024', '0', '1', '1']);
        $this->get('/admin/reports/credits?from=2024-05-01&to=2024-06-30')->assertOk()->assertSeeInOrder(['May 2024', '$20.00', 'Jun 2024', '$0.00', '$8.50']);
        $this->get('/admin/reports/suspensions-and-cancellations?from=2025-01-01&to=2025-12-31')->assertOk()->assertSee('Moving to a bigger plan');
        $this->get('/admin/reports/response-times?from=2026-10-01&to=2026-10-02')->assertOk()->assertSeeInOrder(['Support', '1', '1', '1h 0m']);
        $this->assertSame('2026-10-01 11:00:00', Ticket::find(60)->first_response_at->toDateTimeString());
    }
}
