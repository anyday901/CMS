<?php

namespace App\Reports;

use App\Billing\CreditApplier;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One report under Reports: what it is called, which filters its form
 * shows, and the tables it gives for the chosen filters.
 */
abstract class Report
{
    public const DATES = 'dates';

    public const CURRENCY = 'currency';

    public const CLIENT = 'client';

    /** The report's address, such as "annual-income". */
    abstract public function key(): string;

    abstract public function title(): string;

    /** Which section of the Reports menu it is in, one of ReportRegistry::GROUPS. */
    abstract public function group(): string;

    /** One sentence for the Reports menu and the top of the report. */
    abstract public function description(): string;

    abstract public function run(ReportFilters $filters): ReportResult;

    /** @return list<string> the filters the form shows */
    public function filters(): array
    {
        return [self::DATES, self::CURRENCY];
    }

    /**
     * Extra dropdowns: name => [label, [value => label]]. The first value is the default.
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public function options(): array
    {
        return [];
    }

    /**
     * The dates shown when none are chosen: the last 12 months by default.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function defaultRange(CarbonImmutable $today): array
    {
        return [$today->subMonthsNoOverflow(11)->startOfMonth(), $today];
    }

    /**
     * Money that came in (payments, less refunds) in the chosen currency
     * and dates. Credit applied to an invoice isn't new money, so it is
     * left out, and so are refunds the gateway hasn't finished.
     *
     * @return Builder<Transaction>
     */
    protected function payments(ReportFilters $filters): Builder
    {
        return Transaction::query()
            ->where('transactions.gateway', '!=', CreditApplier::GATEWAY)
            ->where('transactions.pending', false)
            ->where('transactions.currency', $filters->currency)
            ->whereBetween('transactions.created_at', $filters->between());
    }
}
