<?php

namespace App\Import\Whmcs;

use App\Billing\InvoiceGenerator;
use App\Enums\InvoiceStatus;
use App\Events\InvoiceIssued;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\TaxRule;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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

    /** WHMCS invoice line types for things the importer doesn't bring over. */
    private const NOT_IMPORTED = ['Addon', 'Domain', 'DomainRegister', 'DomainTransfer'];

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ImportException when nothing has been imported yet
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
            throw new ImportException('Nothing has been imported from WHMCS yet. Run php artisan whmcs:import first.');
        }

        return (int) $saved['last_invoice_id'];
    }

    /** @return list<array<string, mixed>> */
    private function lines(ConnectionInterface $source, int $after, CarbonInterface $from, CarbonInterface $to): array
    {
        $inclusive = $source->getSchemaBuilder()->hasTable('tblconfiguration')
            && $source->table('tblconfiguration')->where('setting', 'TaxType')->value('value') === 'Inclusive';

        // Renewals of services, plus add-ons and domains, which aren't imported, so they show as WHMCS-only.
        $whmcs = $source->table('tblinvoiceitems')
            ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
            ->where('tblinvoices.id', '>', $after)
            ->where('tblinvoices.status', '!=', 'Cancelled')
            ->whereIn('tblinvoiceitems.type', ['Hosting', ...self::NOT_IMPORTED])
            ->whereBetween('tblinvoiceitems.duedate', [$from->toDateString(), $to->toDateString()])
            ->get(['tblinvoiceitems.type', 'tblinvoiceitems.relid', 'tblinvoiceitems.duedate', 'tblinvoiceitems.amount', 'tblinvoiceitems.taxed', 'tblinvoices.taxrate', 'tblinvoices.taxrate2'])
            ->groupBy(fn ($row) => ($row->type === 'Hosting' ? '' : "{$row->type}:").(int) $row->relid.'|'.Whmcs::date($row->duedate))
            ->map(fn ($rows) => $this->totals($rows->map(fn ($row) => [
                Whmcs::cents($row->amount),
                Whmcs::on($row->taxed) ? [Whmcs::cents($row->taxrate), Whmcs::cents($row->taxrate2 ?? 0)] : [],
                $inclusive,
            ])));

        $here = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.id', '>', $after)
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->where('invoice_items.type', InvoiceItem::TYPE_SERVICE)
            ->whereDate('invoice_items.period_start', '>=', $from->toDateString())
            ->whereDate('invoice_items.period_start', '<=', $to->toDateString())
            ->get(['invoice_items.service_id', 'invoice_items.period_start', 'invoice_items.amount', 'invoice_items.taxable', 'invoices.tax_rate', 'invoices.tax_inclusive'])
            ->groupBy(fn ($row) => (int) $row->service_id.'|'.substr((string) $row->period_start, 0, 10))
            ->map(fn ($rows) => $this->totals($rows->map(fn ($row) => [
                (int) $row->amount,
                $row->taxable ? [(int) $row->tax_rate] : [],
                (bool) $row->tax_inclusive,
            ])));

        $keys = $whmcs->keys()->merge($here->keys())->unique()->sort()->values();
        $services = DB::table('services')
            ->join('clients', 'clients.id', '=', 'services.client_id')
            ->join('products', 'products.id', '=', 'services.product_id')
            ->whereIn('services.id', $keys->filter(fn (string $key) => ! str_contains($key, ':'))->map(fn (string $key) => (int) explode('|', $key)[0])->unique()->all())
            ->get(['services.id', 'services.label', 'products.name', 'clients.first_name', 'clients.last_name', 'clients.currency'])
            ->keyBy('id');

        return $keys->map(function (string $key) use ($whmcs, $here, $services) {
            [$item, $start] = explode('|', $key);
            [$type, $id] = str_contains($item, ':') ? explode(':', $item) : [null, $item];
            $service = $type === null ? $services->get((int) $id) : null;
            $theirs = $whmcs->get($key);
            $ours = $here->get($key);

            return [
                'result' => match (true) {
                    $ours === null => self::ONLY_WHMCS,
                    $theirs === null => self::ONLY_HERE,
                    $theirs === $ours => self::MATCHED,
                    default => self::DIFFERENT,
                },
                'service_id' => (int) $id,
                'service' => match (true) {
                    $type !== null => "WHMCS {$type} {$id} (not imported: add it as a service or invoice line by hand)",
                    $service !== null => trim("{$service->name} {$service->label}")." ({$service->first_name} {$service->last_name})",
                    default => "Service {$id} (not imported)",
                },
                'period_start' => $start,
                'whmcs' => $theirs['amount'] ?? null,
                'here' => $ours['amount'] ?? null,
                'whmcs_tax' => $theirs['tax'] ?? null,
                'here_tax' => $ours['tax'] ?? null,
                'currency' => $service->currency ?? (string) config('billing.currency'),
            ];
        })->all();
    }

    /**
     * Amount and tax of a group of lines, each [amount, rates in hundredths of a percent, tax inclusive].
     *
     * @param  Collection<int, array{0: int, 1: list<int>, 2: bool}>  $lines
     * @return array{amount: int, tax: int}
     */
    private function totals(Collection $lines): array
    {
        return [
            'amount' => (int) $lines->sum(fn (array $line) => $line[0]),
            'tax' => (int) $lines->sum(fn (array $line) => array_sum(array_map(
                fn (int $rate) => $line[2] ? TaxRule::taxIncludedIn($line[0], $rate) : TaxRule::taxOn($line[0], $rate),
                $line[1],
            ))),
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
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

        $out = [...$out, '', '| Result | Service | Period starts | WHMCS | Tax | Here | Tax |', '| --- | --- | --- | ---: | ---: | ---: | ---: |'];

        foreach ($lines as $line) {
            if ($line['result'] === self::MATCHED) {
                continue;
            }

            $format = fn (?int $cents) => $cents === null ? '-' : Money::format($cents, $line['currency']);
            $out[] = "| {$line['result']} | {$line['service']} | {$line['period_start']} | {$format($line['whmcs'])} | {$format($line['whmcs_tax'])} | {$format($line['here'])} | {$format($line['here_tax'])} |";
        }

        return implode("\n", $out)."\n";
    }
}
