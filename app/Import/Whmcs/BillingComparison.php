<?php

namespace App\Import\Whmcs;

use App\Billing\InvoiceGenerator;
use App\Enums\InvoiceStatus;
use App\Events\InvoiceIssued;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Compares the renewal invoices WHMCS and this app made for the same billing
 * period, line by line, after an import. Both sides only count invoices made
 * after the import, so the two systems can run side by side for a cycle.
 *
 * With simulate, this app's renewals are generated for the period first and
 * thrown away afterwards: no emails, no credit applied, nothing saved.
 */
class BillingComparison
{
    public const MATCHED = 'matched';

    public const DIFFERENT = 'different amount';

    public const ONLY_WHMCS = 'only in WHMCS';

    public const ONLY_HERE = 'only here';

    /**
     * @return list<array{result: string, service_id: int, service: string, period_start: string, whmcs: ?int, here: ?int, currency: string}>
     *
     * @throws RuntimeException when nothing has been imported yet
     */
    public function compare(ConnectionInterface $source, CarbonInterface $from, CarbonInterface $to, bool $simulate): array
    {
        $after = self::lastImportedInvoice();

        if (! $simulate) {
            return $this->lines($source, $after, $from, $to);
        }

        $credit = config('billing.apply_credit_automatically');
        DB::beginTransaction();

        try {
            config(['billing.apply_credit_automatically' => false]);

            Event::fakeFor(function () use ($to) {
                $today = $to->copy()->subDays((int) config('billing.invoice_days_before_due'));
                app(InvoiceGenerator::class)->generate($today);
            }, [InvoiceIssued::class]);

            return $this->lines($source, $after, $from, $to);
        } finally {
            config(['billing.apply_credit_automatically' => $credit]);
            DB::rollBack();
        }
    }

    /** The highest WHMCS invoice id at import time. */
    public static function lastImportedInvoice(): int
    {
        $saved = json_decode((string) Setting::find(WhmcsImporter::SETTING)?->value, true);

        if (! is_array($saved)) {
            throw new RuntimeException('Nothing has been imported from WHMCS yet. Run php artisan whmcs:import first.');
        }

        return (int) $saved['last_invoice_id'];
    }

    /** @return list<array{result: string, service_id: int, service: string, period_start: string, whmcs: ?int, here: ?int, currency: string}> */
    private function lines(ConnectionInterface $source, int $after, CarbonInterface $from, CarbonInterface $to): array
    {
        $whmcs = $source->table('tblinvoiceitems')
            ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
            ->where('tblinvoices.id', '>', $after)
            ->where('tblinvoices.status', '!=', 'Cancelled')
            ->where('tblinvoiceitems.type', 'Hosting')
            ->whereBetween('tblinvoiceitems.duedate', [$from->toDateString(), $to->toDateString()])
            ->get(['tblinvoiceitems.relid', 'tblinvoiceitems.duedate', 'tblinvoiceitems.amount'])
            ->groupBy(fn ($row) => (int) $row->relid.'|'.Whmcs::date($row->duedate))
            ->map(fn ($rows) => $rows->sum(fn ($row) => Whmcs::cents($row->amount)));

        $here = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.id', '>', $after)
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->where('invoice_items.type', InvoiceItem::TYPE_SERVICE)
            ->whereBetween('invoice_items.period_start', [$from->toDateString(), $to->toDateString()])
            ->get(['invoice_items.service_id', 'invoice_items.period_start', 'invoice_items.amount'])
            ->groupBy(fn ($row) => (int) $row->service_id.'|'.substr((string) $row->period_start, 0, 10))
            ->map(fn ($rows) => (int) $rows->sum('amount'));

        $keys = $whmcs->keys()->merge($here->keys())->unique()->sort()->values();
        $services = DB::table('services')
            ->join('clients', 'clients.id', '=', 'services.client_id')
            ->join('products', 'products.id', '=', 'services.product_id')
            ->whereIn('services.id', $keys->map(fn (string $key) => (int) explode('|', $key)[0])->unique()->all())
            ->get(['services.id', 'services.label', 'products.name', 'clients.first_name', 'clients.last_name', 'clients.currency'])
            ->keyBy('id');

        return $keys->map(function (string $key) use ($whmcs, $here, $services) {
            [$serviceId, $start] = explode('|', $key);
            $service = $services->get((int) $serviceId);
            $theirs = $whmcs->get($key);
            $ours = $here->get($key);

            return [
                'result' => match (true) {
                    $ours === null => self::ONLY_WHMCS,
                    $theirs === null => self::ONLY_HERE,
                    $theirs === $ours => self::MATCHED,
                    default => self::DIFFERENT,
                },
                'service_id' => (int) $serviceId,
                'service' => $service
                    ? trim("{$service->name} {$service->label}")." ({$service->first_name} {$service->last_name})"
                    : "Service {$serviceId} (not imported)",
                'period_start' => $start,
                'whmcs' => $theirs,
                'here' => $ours,
                'currency' => $service->currency ?? (string) config('billing.currency'),
            ];
        })->all();
    }

    /** @param  list<array{result: string, service_id: int, service: string, period_start: string, whmcs: ?int, here: ?int, currency: string}>  $lines */
    public static function toMarkdown(array $lines, CarbonInterface $from, CarbonInterface $to, bool $simulate): string
    {
        $counts = array_count_values(array_column($lines, 'result'));
        $out = [
            '# WHMCS billing comparison',
            '',
            "Renewals due {$from->toDateString()} to {$to->toDateString()}, on invoices made after the import.".($simulate ? ' This app\'s renewals were simulated and not saved.' : ''),
            '',
        ];

        foreach ([self::MATCHED, self::DIFFERENT, self::ONLY_WHMCS, self::ONLY_HERE] as $result) {
            $out[] = '- '.ucfirst($result).': '.($counts[$result] ?? 0);
        }

        $out = [...$out, '', '| Result | Service | Period starts | WHMCS | Here |', '| --- | --- | --- | ---: | ---: |'];

        foreach ($lines as $line) {
            if ($line['result'] === self::MATCHED) {
                continue;
            }

            $format = fn (?int $cents) => $cents === null ? '-' : Money::format($cents, $line['currency']);
            $out[] = "| {$line['result']} | {$line['service']} | {$line['period_start']} | {$format($line['whmcs'])} | {$format($line['here'])} |";
        }

        return implode("\n", $out)."\n";
    }
}
