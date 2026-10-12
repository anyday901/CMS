<?php

namespace App\Import\Whmcs;

use App\Billing\CreditApplier;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Totals in WHMCS against the same totals here, by currency, for the import report. */
class TotalsCheck
{
    /** @var array<int|string, string> WHMCS invoice id => amount paid in WHMCS */
    private array $whmcsPaid = [];

    /** @param  array<int, string>  $invoices  imported invoice ids => currency */
    public function __construct(private ImportRun $run, private array $invoices) {}

    public function check(): void
    {
        $count = fn (string $table) => (string) DB::table($table)->count();

        $this->run->report->check('Clients', (string) $this->run->table('tblclients')->count(), $count('clients'));
        $this->run->report->check('Services', (string) $this->run->table('tblhosting')->count(), $count('services'));
        $this->run->report->check('Invoices', (string) $this->run->table('tblinvoices')->count(), $count('invoices'));
        $this->run->report->check('Tickets', (string) $this->run->table('tbltickets')->count(), $count('tickets'));

        $whmcsClients = $this->run->table('tblclients')->pluck('currency', 'id');
        $currencyOf = fn ($clientId) => $this->run->currency($whmcsClients[$clientId] ?? 0);

        $this->byCurrency(
            'Recurring amount of active and suspended services',
            $this->run->table('tblhosting')->whereIn('domainstatus', ['Active', 'Suspended'])->get(['userid', 'amount', 'billingcycle'])
                ->reject(fn ($row) => strtolower(str_replace(' ', '', (string) $row->billingcycle)) === 'freeaccount')
                ->groupBy(fn ($row) => $currencyOf($row->userid))
                ->map(fn ($rows) => $rows->sum(fn ($row) => Whmcs::cents($row->amount))),
            DB::table('services')->join('clients', 'clients.id', '=', 'services.client_id')
                ->whereIn('services.status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
                ->groupBy('clients.currency')->selectRaw('clients.currency as k, sum(services.recurring_amount) as total')->pluck('total', 'k'),
        );

        $this->whmcsPaid = $this->run->table('tblaccounts')->where('invoiceid', '>', 0)->groupBy('invoiceid')
            ->selectRaw('invoiceid as k, sum(amountin) - sum(amountout) as total')->pluck('total', 'k')->all();

        $this->byCurrency(
            'Balance due on unpaid invoices',
            $this->run->table('tblinvoices')->whereIn('status', ['Unpaid', 'Collections', 'Payment Pending'])->get(['id', 'userid', 'total'])
                ->groupBy(fn ($row) => $currencyOf($row->userid))
                ->map(fn ($rows) => $rows->sum(fn ($row) => Whmcs::cents($row->total) - Whmcs::cents($this->whmcsPaid[$row->id] ?? 0))),
            DB::table('invoices')->where('status', InvoiceStatus::Unpaid->value)
                ->leftJoinSub(DB::table('transactions')->whereNotNull('invoice_id')->groupBy('invoice_id')->select('invoice_id', DB::raw('sum(amount) as paid')), 'paid', 'paid.invoice_id', '=', 'invoices.id')
                ->groupBy('invoices.currency')->selectRaw('invoices.currency as k, sum(invoices.total - coalesce(paid.paid, 0)) as total')->pluck('total', 'k'),
        );

        $this->byCurrency(
            'Payments received, less refunds',
            $this->run->table('tblaccounts')->where('userid', '>', 0)->get(['userid', 'currency', 'amountin', 'amountout'])
                ->groupBy(fn ($row) => (int) $row->currency > 0 ? $this->run->currency($row->currency) : $currencyOf($row->userid))
                ->map(fn ($rows) => $rows->sum(fn ($row) => Whmcs::cents($row->amountin) - Whmcs::cents($row->amountout))),
            DB::table('transactions')->where('gateway', '!=', CreditApplier::GATEWAY)
                ->groupBy('currency')->selectRaw('currency as k, sum(amount) as total')->pluck('total', 'k'),
        );

        $this->byCurrency(
            'Client credit balances',
            $this->run->table('tblclients')->get(['currency', 'credit'])
                ->groupBy(fn ($row) => $this->run->currency($row->currency))
                ->map(fn ($rows) => $rows->sum(fn ($row) => Whmcs::cents($row->credit))),
            DB::table('clients')->groupBy('currency')->selectRaw('currency as k, sum(credit_balance) as total')->pluck('total', 'k'),
        );

        $this->invoiceBalances();
    }

    /**
     * @param  iterable<string, int|string>  $whmcs
     * @param  iterable<string, int|string>  $here
     */
    private function byCurrency(string $label, iterable $whmcs, iterable $here): void
    {
        $whmcs = collect($whmcs)->map(fn ($cents) => (int) $cents);
        $here = collect($here)->map(fn ($cents) => (int) $cents);

        foreach ($whmcs->keys()->merge($here->keys())->unique()->sort() as $currency) {
            $this->run->report->check(
                "{$label} ({$currency})",
                Money::format($whmcs->get($currency, 0), $currency),
                Money::format($here->get($currency, 0), $currency),
            );
        }
    }

    /** Notes every imported invoice whose balance due differs from WHMCS. */
    private function invoiceBalances(): void
    {
        $paidHere = DB::table('transactions')->whereNotNull('invoice_id')->groupBy('invoice_id')->selectRaw('invoice_id as k, sum(amount) as total')->pluck('total', 'k');

        foreach ($this->run->table('tblinvoices')->orderBy('id')->cursor() as $row) {
            if (! isset($this->invoices[(int) $row->id])) {
                continue;
            }

            $whmcs = Whmcs::cents($row->total) - Whmcs::cents($this->whmcsPaid[$row->id] ?? 0);
            $here = Whmcs::cents($row->total) + Whmcs::cents($row->credit ?? 0) - (int) ($paidHere[$row->id] ?? 0);

            if ($whmcs !== $here) {
                $currency = $this->invoices[(int) $row->id];
                $this->run->report->note('Invoices', "Invoice {$row->id} has ".Money::format($here, $currency).' due here but '.Money::format($whmcs, $currency).' in WHMCS.');
            }
        }
    }
}
