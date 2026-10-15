<?php

namespace App\Reports\Income;

use App\Models\CancellationRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Reports\Periods;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Reports\Sql;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** What happened each day: orders, invoices, payments, tickets and cancellations. */
class DailyPerformance extends Report
{
    public function key(): string
    {
        return 'daily-performance';
    }

    public function title(): string
    {
        return 'Daily performance';
    }

    public function group(): string
    {
        return ReportRegistry::INCOME;
    }

    public function description(): string
    {
        return 'Each day\'s orders, new and paid invoices, income, tickets, staff replies and cancellation requests.';
    }

    public function defaultRange(CarbonImmutable $today): array
    {
        return [$today->startOfMonth(), $today];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $between = $filters->between();
        $inCurrency = fn (Builder $query) => $query->whereHas('client', fn ($client) => $client->where('currency', $filters->currency));

        $counts = [
            'orders' => $this->perDay($inCurrency(Order::query())->whereBetween('orders.created_at', $between), 'orders.created_at'),
            'invoices' => $this->perDay(Invoice::where('currency', $filters->currency)->whereIn('status', ['unpaid', 'paid', 'refunded'])->whereBetween('invoices.created_at', $between), 'invoices.created_at'),
            'paid' => $this->perDay(Invoice::where('currency', $filters->currency)->whereNotNull('paid_at')->whereBetween('paid_at', $between), 'paid_at'),
            'tickets' => $this->perDay(Ticket::whereNull('merged_into_id')->whereBetween('tickets.created_at', $between), 'tickets.created_at'),
            'replies' => $this->perDay(TicketMessage::whereNull('client_id')->where('guest', false)->where('internal', false)->whereBetween('ticket_messages.created_at', $between), 'ticket_messages.created_at'),
            'cancellations' => $this->perDay(CancellationRequest::whereBetween('cancellation_requests.created_at', $between), 'cancellation_requests.created_at'),
        ];
        $day = Sql::day('transactions.created_at');
        $income = Sql::by($this->payments($filters)->toBase(), $day, 'SUM(transactions.amount)');

        $table = new ReportTable([
            'day' => ['Day', ReportTable::DATE],
            'orders' => ['Orders', ReportTable::NUMBER],
            'invoices' => ['New invoices', ReportTable::NUMBER],
            'paid' => ['Paid invoices', ReportTable::NUMBER],
            'income' => ['Income', ReportTable::MONEY],
            'tickets' => ['New tickets', ReportTable::NUMBER],
            'replies' => ['Staff replies', ReportTable::NUMBER],
            'cancellations' => ['Cancellation requests', ReportTable::NUMBER],
        ], currency: $filters->currency);
        $table->chart = 'income';

        foreach (Periods::days($filters->from, $filters->to) as $date) {
            $row = ['day' => $date];
            foreach ($counts as $key => $values) {
                $row[$key] = (int) ($values[$date] ?? 0);
            }
            $row['income'] = (int) ($income[$date] ?? 0);
            $table->add($row);
        }

        return (new ReportResult)->table($table->withTotals(['day' => null]))
            ->note('Orders, invoices and income are for clients billed in the chosen currency; tickets and cancellation requests are for everyone.');
    }

    /** @return Collection<string, int> "2026-10-15" => count */
    private function perDay(Builder $query, string $column): Collection
    {
        return Sql::by($query->toBase(), Sql::day($column), 'COUNT(*)');
    }
}
