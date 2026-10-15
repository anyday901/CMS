<?php

namespace App\Reports\Invoices;

use App\Reports\Periods;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Reports\Sql;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Credit added to clients' balances and credit spent, by month and by client. */
class Credits extends Report
{
    public function key(): string
    {
        return 'credits';
    }

    public function title(): string
    {
        return 'Credits given and used';
    }

    public function group(): string
    {
        return ReportRegistry::INVOICES;
    }

    public function description(): string
    {
        return 'Credit added to clients\' balances and credit spent on invoices, by month and by client.';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $sums = [
            DB::raw('SUM(CASE WHEN credit_entries.amount > 0 THEN credit_entries.amount ELSE 0 END) as given'),
            DB::raw('SUM(CASE WHEN credit_entries.amount < 0 THEN -credit_entries.amount ELSE 0 END) as used'),
        ];
        $month = Sql::month('credit_entries.created_at');
        $byMonth = $this->entries($filters)->groupBy(DB::raw($month))->get([DB::raw("{$month} as month"), ...$sums])->keyBy('month');

        $table = new ReportTable([
            'month' => ['Month', ReportTable::MONTH],
            'given' => ['Given', ReportTable::MONEY],
            'used' => ['Used', ReportTable::MONEY],
            'net' => ['Change in balances', ReportTable::MONEY],
        ], currency: $filters->currency);
        $table->chart = 'given';
        foreach (Periods::months($filters->from, $filters->to) as $key) {
            $given = (int) ($byMonth->get($key)->given ?? 0);
            $used = (int) ($byMonth->get($key)->used ?? 0);
            $table->add(['month' => $key, 'given' => $given, 'used' => $used, 'net' => $given - $used]);
        }

        $clients = new ReportTable([
            'client' => ['Client', ReportTable::TEXT],
            'given' => ['Given', ReportTable::MONEY],
            'used' => ['Used', ReportTable::MONEY],
            'balance' => ['Balance now', ReportTable::MONEY],
        ], 'By client', $filters->currency);
        $rows = $this->entries($filters)
            ->groupBy('clients.id', 'clients.first_name', 'clients.last_name', 'clients.credit_balance')
            ->orderByRaw('SUM(ABS(credit_entries.amount)) DESC')
            ->get(['clients.id', 'clients.first_name', 'clients.last_name', 'clients.credit_balance', ...$sums]);
        foreach ($rows as $row) {
            $clients->add([
                'client' => trim("{$row->first_name} {$row->last_name}"),
                'given' => (int) $row->given,
                'used' => (int) $row->used,
                'balance' => (int) $row->credit_balance,
                '_links' => ['client' => route('admin.clients.show', $row->id)],
            ]);
        }

        $outstanding = (int) DB::table('clients')->where('currency', $filters->currency)->sum('credit_balance');

        return (new ReportResult)
            ->table($table->withTotals(['month' => null]))
            ->table($clients->withTotals(['client' => 'Total']))
            ->note('Given is credit added to balances: by staff, from overpayments, refunds to credit and downgrades. Used is credit spent on invoices or taken off by staff.')
            ->note('Clients billed in '.$filters->currency.' hold '.Money::format($outstanding, $filters->currency).' of credit in all today.');
    }

    private function entries(ReportFilters $filters): Builder
    {
        return DB::table('credit_entries')
            ->join('clients', 'clients.id', '=', 'credit_entries.client_id')
            ->where('clients.currency', $filters->currency)
            ->whereBetween('credit_entries.created_at', $filters->between());
    }
}
