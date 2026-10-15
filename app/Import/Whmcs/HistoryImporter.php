<?php

namespace App\Import\Whmcs;

use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Models\CancellationRequest;
use App\Models\Promotion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The history reports read, so they cover the years before the switch:
 * promotions and the orders that used them, the credit log, cancellation
 * requests and the activity log. Invoices, payments, services and tickets
 * come over with the rest of the import.
 */
class HistoryImporter
{
    private const PROMOTIONS = 'Promotions';

    private const ORDERS = 'Orders';

    private const CREDIT = 'Credit log';

    private const CANCELLATIONS = 'Cancellation requests';

    private const ACTIVITY = 'Activity log';

    /** WHMCS promotion types this app has. Price overrides and free setup aren't. */
    private const PROMOTION_TYPES = ['percentage' => Promotion::PERCENT, 'fixed amount' => Promotion::FIXED];

    /** What the WHMCS activity log calls automatic actions. */
    private const SYSTEM_USERS = ['', 'system', 'automated task', 'cron'];

    /** @var array<string, int> lower-case code => promotion id */
    private array $promotions = [];

    /**
     * @param  array<int, string>  $clients  imported client id => currency
     * @param  array<int, mixed>  $services  imported service ids as keys
     * @param  array<int, string>  $invoices  imported invoice id => currency
     * @param  array<int, array{id: int}>  $products  WHMCS product id => the product here
     */
    public function __construct(
        private ImportRun $run,
        private array $clients,
        private array $services,
        private array $invoices,
        private array $products,
    ) {}

    public function importPromotions(): void
    {
        if (! $this->run->hasTables('tblpromotions')) {
            return;
        }

        $existing = DB::table('promotions')->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtolower($code) => (int) $id])->all();
        $unsupported = [];

        foreach ($this->run->table('tblpromotions')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::PROMOTIONS);
            $code = Str::limit(trim(Whmcs::text($row->code ?? '')), 250, '');

            if ($code === '') {
                $this->run->report->skipped(self::PROMOTIONS, "Promotion {$row->id} has no code.");

                continue;
            }

            if (isset($existing[strtolower($code)]) || isset($this->promotions[strtolower($code)])) {
                $this->promotions[strtolower($code)] ??= $existing[strtolower($code)];
                $this->run->report->note(self::PROMOTIONS, "Promotion {$code} already exists here, so it was kept as it is.");

                continue;
            }

            $whmcsType = strtolower(trim((string) ($row->type ?? '')));
            $type = self::PROMOTION_TYPES[$whmcsType] ?? null;
            $expires = Whmcs::date($row->expirationdate ?? null);

            if ($type === null) {
                $unsupported[] = $code;
            }

            $this->promotions[strtolower($code)] = DB::table('promotions')->insertGetId([
                'code' => $code,
                'type' => $type ?? Promotion::FIXED,
                // Hundredths of a percent for percent codes, cents for fixed ones.
                'value' => max(0, Whmcs::cents($row->value ?? 0)),
                'recurring' => Whmcs::on($row->recurring ?? ''),
                'product_ids' => $this->productIds((string) ($row->appliesto ?? '')),
                'starts_on' => Whmcs::day(Whmcs::date($row->startdate ?? null)),
                'expires_on' => Whmcs::day($expires),
                'max_uses' => (int) ($row->maxuses ?? 0) > 0 ? (int) $row->maxuses : null,
                'uses' => max(0, (int) ($row->uses ?? 0)),
                'once_per_client' => Whmcs::on($row->onceperclient ?? ''),
                // Types this app can't apply come over switched off, so they can't be used by mistake.
                'active' => $type !== null,
                'notes' => Whmcs::text($row->notes ?? '') ?: null,
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);
            $this->run->report->imported(self::PROMOTIONS);
        }

        if ($unsupported !== []) {
            $this->run->report->note(self::PROMOTIONS, 'These codes were price overrides or free setup in WHMCS, which this app doesn\'t have, so they came over switched off for their history: '.implode(', ', $unsupported).'.');
        }
    }

    /** Orders keep their WHMCS ids and order numbers, and their services and promotion are linked. */
    public function importOrders(): void
    {
        if (! $this->run->hasTables('tblorders')) {
            return;
        }

        foreach ($this->run->table('tblorders')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::ORDERS);

            if (! isset($this->clients[(int) $row->userid])) {
                $this->run->report->skipped(self::ORDERS, "Order {$row->id} belongs to client {$row->userid}, which wasn't imported.");

                continue;
            }

            $this->run->queue('orders', $this->order($row));
            $this->run->report->imported(self::ORDERS);
        }

        $this->run->flush('orders');
        $this->linkServices();
    }

    /** @return array<string, mixed> */
    private function order(object $row): array
    {
        $code = Str::limit(trim(Whmcs::text($row->promocode ?? '')), 250, '') ?: null;
        $placed = Whmcs::dateTime($row->date ?? null) ?? $this->run->now;
        $status = self::orderStatus((string) ($row->status ?? ''));
        $number = (int) ($row->ordernum ?? 0);
        $invoice = (int) ($row->invoiceid ?? 0);

        return [
            'id' => (int) $row->id,
            'whmcs_number' => $number > 0 ? (string) $number : null,
            'client_id' => (int) $row->userid,
            'invoice_id' => isset($this->invoices[$invoice]) ? $invoice : null,
            'status' => $status->value,
            'promotion_id' => $code !== null ? ($this->promotions[strtolower($code)] ?? null) : null,
            'promotion_code' => $code,
            'ip_address' => Str::limit(trim((string) ($row->ipaddress ?? '')), 45, '') ?: null,
            'reviewed_at' => $status === OrderStatus::Pending ? null : $placed,
            'created_at' => $placed,
            'updated_at' => $this->run->now,
        ];
    }

    /** WHMCS's own order statuses; custom ones count as accepted. */
    private static function orderStatus(string $status): OrderStatus
    {
        return match (strtolower(trim($status))) {
            'pending' => OrderStatus::Pending,
            'cancelled', 'canceled' => OrderStatus::Cancelled,
            'fraud' => OrderStatus::Fraud,
            default => OrderStatus::Active,
        };
    }

    /** Each service's order and the promotion it was ordered with. */
    private function linkServices(): void
    {
        $orders = DB::table('orders')->pluck('id')->flip();
        $byOrder = [];
        $byPromotion = [];
        $promotionIds = $this->run->hasTables('tblpromotions') ? $this->run->table('tblpromotions')->pluck('code', 'id')->all() : [];

        foreach ($this->run->table('tblhosting')->orderBy('id')->cursor() as $row) {
            if (! isset($this->services[(int) $row->id])) {
                continue;
            }
            if (isset($orders[(int) ($row->orderid ?? 0)])) {
                $byOrder[(int) $row->orderid][] = (int) $row->id;
            }
            $code = strtolower(trim(Whmcs::text($promotionIds[(int) ($row->promoid ?? 0)] ?? '')));
            if ($code !== '' && isset($this->promotions[$code])) {
                $byPromotion[$this->promotions[$code]][] = (int) $row->id;
            }
        }

        foreach ($byOrder as $order => $ids) {
            DB::table('services')->whereIn('id', $ids)->update(['order_id' => $order]);
        }
        foreach ($byPromotion as $promotion => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('services')->whereIn('id', $chunk)->update(['promotion_id' => $promotion]);
            }
        }
    }

    /**
     * The credit log, oldest first, with each client's balance after every
     * line. Where the log doesn't add up to the balance WHMCS shows (WHMCS
     * changes it without a log line in places), one line today makes up
     * the difference, so the balance here always matches WHMCS.
     *
     * @param  array<int, int>  $balances  client id => their WHMCS credit balance, in cents
     */
    public function importCredit(array $balances): void
    {
        $running = $this->importCreditLog();
        $adjusted = 0;

        foreach ($balances as $client => $balance) {
            $logged = $running[$client] ?? 0;

            if ($balance === $logged) {
                continue;
            }

            DB::table('credit_entries')->insert([
                'client_id' => $client,
                'amount' => $balance - $logged,
                'balance_after' => $balance,
                'description' => isset($running[$client]) ? 'Difference between the WHMCS credit log and balance' : 'Credit balance brought over from WHMCS',
                'created_at' => $this->run->now,
            ]);
            $adjusted += isset($running[$client]) ? 1 : 0;
        }

        if ($adjusted > 0) {
            $this->run->report->note(self::CREDIT, "For {$adjusted} client(s) the WHMCS credit log didn't add up to their credit balance, so a line dated today makes up the difference. Their balance here is the one WHMCS shows.");
        }
    }

    /**
     * Queues the credit log lines and returns each client's balance at the
     * end of the log.
     *
     * @return array<int, int>
     */
    private function importCreditLog(): array
    {
        $running = [];

        if (! $this->run->hasTables('tblcredit')) {
            return $running;
        }

        foreach ($this->run->table('tblcredit')->orderBy('date')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::CREDIT);
            $client = (int) $row->clientid;

            if (! isset($this->clients[$client])) {
                $this->run->report->skipped(self::CREDIT, "Credit log line {$row->id} belongs to client {$client}, which wasn't imported.");

                continue;
            }

            $amount = Whmcs::cents($row->amount ?? 0);
            $running[$client] = ($running[$client] ?? 0) + $amount;
            $this->run->queue('credit_entries', $this->creditEntry($row, $client, $amount, $running[$client]));
            $this->run->report->imported(self::CREDIT);
        }

        $this->run->flush('credit_entries');

        return $running;
    }

    /** @return array<string, mixed> */
    private function creditEntry(object $row, int $client, int $amount, int $balance): array
    {
        $invoice = (int) ($row->relid ?? 0);

        return [
            'client_id' => $client,
            'amount' => $amount,
            'balance_after' => $balance,
            'description' => Str::limit(Whmcs::text($row->description ?? ''), 250, '') ?: 'Credit from WHMCS',
            'invoice_id' => isset($this->invoices[$invoice]) ? $invoice : null,
            'created_at' => Whmcs::dateTime($row->date ?? null) ?? $this->run->now,
        ];
    }

    /**
     * Requests to cancel. Ones for services WHMCS already cancelled or
     * terminated came over carried out; the rest are still waiting, and the
     * billing run carries them out on their date as WHMCS would have.
     */
    public function importCancellations(): void
    {
        if (! $this->run->hasTables('tblcancelrequests')) {
            return;
        }

        $waiting = 0;

        $this->run->chunk('tblcancelrequests', function ($rows) use (&$waiting) {
            $services = DB::table('services')->whereIn('id', $rows->pluck('relid'))->get(['id', 'status', 'next_due_date', 'terminated_at'])->keyBy('id');

            foreach ($rows as $row) {
                $this->run->report->found(self::CANCELLATIONS);
                $service = $services->get((int) $row->relid);

                if ($service === null) {
                    $this->run->report->skipped(self::CANCELLATIONS, "Cancellation request {$row->id} is for service {$row->relid}, which wasn't imported.");

                    continue;
                }

                $ended = in_array($service->status, [ServiceStatus::Cancelled->value, ServiceStatus::Terminated->value], true);
                $waiting += $ended ? 0 : 1;
                $this->run->queue('cancellation_requests', $this->cancellation($row, $service, $ended));
                $this->run->report->imported(self::CANCELLATIONS);
            }
        });

        if ($waiting > 0) {
            $this->run->report->note(self::CANCELLATIONS, "{$waiting} request(s) are for services WHMCS hadn't cancelled yet. They came over waiting, so those services won't renew and the billing run cancels them on their date. Withdraw any that shouldn't go ahead on the service's page before the first billing run.");
        }
    }

    /** @return array<string, mixed> */
    private function cancellation(object $row, object $service, bool $ended): array
    {
        $asked = Whmcs::dateTime($row->date ?? null) ?? $this->run->now;
        $type = str_contains(strtolower((string) ($row->type ?? '')), 'immediate') ? CancellationRequest::IMMEDIATE : CancellationRequest::END_OF_PERIOD;
        $from = $type === CancellationRequest::END_OF_PERIOD && $service->next_due_date ? (string) $service->next_due_date : $asked;

        return [
            'service_id' => (int) $service->id,
            'type' => $type,
            'reason' => Whmcs::text($row->reason ?? '') ?: null,
            'status' => $ended ? CancellationRequest::COMPLETED : CancellationRequest::PENDING,
            'cancel_on' => Whmcs::day(substr($from, 0, 10)),
            'completed_at' => $ended ? ($service->terminated_at ?? $asked) : null,
            'created_at' => $asked,
            'updated_at' => $this->run->now,
        ];
    }

    /** The WHMCS activity log, as it was, with who did each thing. */
    public function importActivity(StaffImporter $staff): void
    {
        if (! $this->run->hasTables('tblactivitylog')) {
            return;
        }

        $this->run->chunk('tblactivitylog', function ($rows) use ($staff) {
            foreach ($rows as $row) {
                $this->run->report->found(self::ACTIVITY);
                $description = Str::limit(Whmcs::text($row->description ?? ''), 250, '');

                if ($description === '') {
                    $this->run->report->skipped(self::ACTIVITY, "Activity log line {$row->id} is empty.");

                    continue;
                }

                $client = (int) ($row->userid ?? 0);
                [$type, $id, $name] = $this->actor($row, $staff);

                $this->run->queue('activity_log', [
                    'actor_type' => $type,
                    'actor_id' => $id,
                    'actor_name' => $name,
                    'client_id' => isset($this->clients[$client]) ? $client : null,
                    'description' => $description,
                    'properties' => json_encode(['imported_from' => 'WHMCS']),
                    'ip_address' => Str::limit(trim((string) ($row->ipaddr ?? '')), 45, '') ?: null,
                    'created_at' => Whmcs::dateTime($row->date ?? null) ?? $this->run->now,
                ]);
                $this->run->report->imported(self::ACTIVITY);
            }
        });

        $days = config('activity.retention_days');
        if (filled($days)) {
            $this->run->report->note(self::ACTIVITY, "The activity log keeps {$days} days here (Settings), so lines older than that are deleted at the next nightly clean-up. Clear that setting first to keep all of WHMCS's log.");
        }
    }

    /**
     * Who a WHMCS activity log line was by: an admin (here as staff when
     * they came over), a client, or WHMCS itself.
     *
     * @return array{0: string, 1: int|null, 2: string}
     */
    private function actor(object $row, StaffImporter $staff): array
    {
        $user = Str::limit(trim(Whmcs::text($row->user ?? '')), 250, '');
        $admin = (int) ($row->admin_id ?? 0);

        return match (true) {
            $admin > 0 => ['staff', $staff->userId($admin), $user !== '' ? $user : 'WHMCS admin'],
            in_array(strtolower($user), self::SYSTEM_USERS, true) => ['system', null, 'System'],
            (int) ($row->userid ?? 0) > 0 && (str_starts_with(strtolower($user), 'client') || str_starts_with(strtolower($user), 'sub-account') || (int) ($row->user_id ?? 0) > 0) => ['client', isset($this->clients[(int) $row->userid]) ? (int) $row->userid : null, $user],
            default => ['staff', null, $user],
        };
    }

    /**
     * WHMCS lists the products a code applies to with add-ons ("A3") and
     * domains ("D.com") among them; only products come over.
     */
    private function productIds(string $appliesTo): ?string
    {
        $ids = collect(explode(',', $appliesTo))
            ->map(fn (string $id) => trim($id))
            ->filter(fn (string $id) => ctype_digit($id) && isset($this->products[(int) $id]))
            ->map(fn (string $id) => $this->products[(int) $id]['id'])
            ->unique()->values()->all();

        return $ids === [] ? null : json_encode($ids);
    }
}
