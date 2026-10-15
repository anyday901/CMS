<?php

namespace App\Reports;

use App\Reports\Clients\ClientBreakdown;
use App\Reports\Clients\ClientGrowth;
use App\Reports\Clients\Retention;
use App\Reports\Clients\ServerRevenue;
use App\Reports\Clients\SuspensionsCancellations;
use App\Reports\Income\AnnualIncome;
use App\Reports\Income\DailyPerformance;
use App\Reports\Income\IncomeForecast;
use App\Reports\Income\MonthlyTransactions;
use App\Reports\Invoices\ClientStatement;
use App\Reports\Invoices\Credits;
use App\Reports\Invoices\InvoiceAging;
use App\Reports\Sales\IncomeByProduct;
use App\Reports\Sales\OrdersAndClients;
use App\Reports\Sales\PromotionUsage;
use App\Reports\Sales\TopClients;
use App\Reports\Support\Feedback;
use App\Reports\Support\ResponseTimes;
use App\Reports\Support\StaffReplies;
use App\Reports\Support\TicketTags;
use App\Reports\Support\TicketVolume;
use Illuminate\Support\Collection;

/** Every report under Reports, in menu order. */
class ReportRegistry
{
    public const INCOME = 'Income';

    public const SALES = 'Sales';

    public const INVOICES = 'Invoices';

    public const TAX = 'Tax';

    public const CLIENTS = 'Clients and services';

    public const SUPPORT = 'Support';

    public const GROUPS = [self::INCOME, self::SALES, self::INVOICES, self::TAX, self::CLIENTS, self::SUPPORT];

    /** @return Collection<string, Report> by key */
    public function all(): Collection
    {
        return collect([
            new AnnualIncome,
            new MonthlyTransactions,
            new DailyPerformance,
            new IncomeForecast,
            new IncomeByProduct,
            new OrdersAndClients,
            new PromotionUsage,
            new TopClients,
            new InvoiceAging,
            new Credits,
            new ClientStatement,
            new Tax\TaxCollected,
            new ClientBreakdown(ClientBreakdown::COUNTRY),
            new ClientBreakdown(ClientBreakdown::GROUP),
            new ClientGrowth,
            new Retention,
            new SuspensionsCancellations,
            new ServerRevenue,
            new TicketVolume,
            new ResponseTimes,
            new StaffReplies,
            new Feedback,
            new TicketTags,
        ])->keyBy(fn (Report $report) => $report->key());
    }

    public function find(string $key): ?Report
    {
        return $this->all()->get($key);
    }

    /** @return array<string, list<Report>> group => its reports */
    public function grouped(): array
    {
        $grouped = array_fill_keys(self::GROUPS, []);
        foreach ($this->all() as $report) {
            $grouped[$report->group()][] = $report;
        }

        return $grouped;
    }
}
