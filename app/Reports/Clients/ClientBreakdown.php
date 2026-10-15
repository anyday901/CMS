<?php

namespace App\Reports\Clients;

use App\Enums\ClientStatus;
use App\Enums\ServiceStatus;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Reports\Sql;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Clients counted by their country or their client group, with what they paid in the dates. */
class ClientBreakdown extends Report
{
    public const COUNTRY = 'country';

    public const GROUP = 'group';

    public function __construct(private string $by = self::COUNTRY) {}

    public function key(): string
    {
        return "clients-by-{$this->by}";
    }

    public function title(): string
    {
        return $this->by === self::GROUP ? 'Clients by group' : 'Clients by country';
    }

    public function group(): string
    {
        return ReportRegistry::CLIENTS;
    }

    public function description(): string
    {
        return $this->by === self::GROUP
            ? 'How many clients each client group has, how many are active, and what they paid in these dates.'
            : 'How many clients are in each country, how many are active, and what they paid in these dates.';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        [$column, $label] = $this->by === self::GROUP ? ['clients.client_group_id', 'Group'] : ['clients.country', 'Country'];
        $between = $filters->between();

        $clients = DB::table('clients')->where('currency', $filters->currency)->groupBy($column)
            ->selectRaw("{$column} as dimension, COUNT(*) as clients, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active, SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as new_clients", [ClientStatus::Active->value, $between[0], $between[1]])
            ->get()->keyBy('dimension');

        $services = Sql::by(DB::table('services')->join('clients', 'clients.id', '=', 'services.client_id')
            ->where('clients.currency', $filters->currency)->where('services.status', ServiceStatus::Active->value), $column, 'COUNT(*)');

        $income = Sql::by($this->payments($filters)->toBase()->join('clients', 'clients.id', '=', 'transactions.client_id'), $column, 'SUM(transactions.amount)');

        $names = $this->by === self::GROUP ? DB::table('client_groups')->pluck('name', 'id') : collect();

        $table = new ReportTable([
            'name' => [$label, ReportTable::TEXT],
            'clients' => ['Clients', ReportTable::NUMBER],
            'active' => ['Active clients', ReportTable::NUMBER],
            'new_clients' => ['New in these dates', ReportTable::NUMBER],
            'services' => ['Active services', ReportTable::NUMBER],
            'income' => ['Income in these dates', ReportTable::MONEY],
        ], currency: $filters->currency);
        $table->chart = 'clients';
        $table->empty = 'No clients are billed in this currency.';

        foreach ($clients->sortByDesc('clients') as $key => $row) {
            $table->add([
                'name' => $this->label((string) $key, $names),
                'clients' => (int) $row->clients,
                'active' => (int) $row->active,
                'new_clients' => (int) $row->new_clients,
                'services' => (int) ($services[$key] ?? 0),
                'income' => (int) ($income[$key] ?? 0),
            ]);
        }

        return (new ReportResult)->table($table->withTotals(['name' => 'Total']))
            ->note('Clients billed in '.$filters->currency.', by the '.($this->by === self::GROUP ? 'group' : 'country').' on their account today. Income is payments less refunds made in these dates.');
    }

    private function blank(): string
    {
        return $this->by === self::GROUP ? 'No group' : 'Unknown';
    }

    /** @param  Collection<int|string, string>  $names */
    private function label(string $key, Collection $names): string
    {
        if ($key === '') {
            return $this->blank();
        }

        return $this->by === self::GROUP ? ($names[$key] ?? 'Deleted group') : $key;
    }
}
