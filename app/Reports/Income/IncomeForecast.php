<?php

namespace App\Reports\Income;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\CancellationRequest;
use App\Models\Service;
use App\Reports\Periods;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Carbon\CarbonImmutable;

/** What active services will bill in the months ahead, if nothing changes. */
class IncomeForecast extends Report
{
    public function key(): string
    {
        return 'income-forecast';
    }

    public function title(): string
    {
        return 'Income forecast';
    }

    public function group(): string
    {
        return ReportRegistry::INCOME;
    }

    public function description(): string
    {
        return 'What active services will renew for in each month ahead, if nothing changes.';
    }

    public function defaultRange(CarbonImmutable $today): array
    {
        return [$today->startOfMonth(), $today->addMonthsNoOverflow(11)->endOfMonth()->startOfDay()];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $months = array_fill_keys(Periods::months($filters->from, $filters->to), ['renewals' => 0, 'amount' => 0]);
        $cycles = [];
        $start = $filters->from->max(CarbonImmutable::today());

        $services = Service::query()
            ->where('status', ServiceStatus::Active)
            ->where('billing_cycle', '!=', BillingCycle::OneTime)
            ->whereNotNull('next_due_date')
            ->whereHas('client', fn ($client) => $client->where('currency', $filters->currency))
            ->whereDoesntHave('cancellationRequests', fn ($request) => $request->where('status', CancellationRequest::PENDING));

        foreach ($services->cursor() as $service) {
            $amount = max(0, (int) $service->recurring_amount - (int) $service->recurring_discount);
            $cycle = $service->billing_cycle;
            $cycles[$cycle->value] ??= ['cycle' => $cycle->label(), 'services' => 0, 'amount' => 0, 'monthly' => 0];
            $cycles[$cycle->value]['services']++;
            $cycles[$cycle->value]['amount'] += $amount;
            $cycles[$cycle->value]['monthly'] += intdiv($amount, $cycle->months());

            for ($due = CarbonImmutable::parse($service->next_due_date); $due->lessThanOrEqualTo($filters->to); $due = CarbonImmutable::parse($cycle->advance($due))) {
                if ($due->greaterThanOrEqualTo($start) && isset($months[$due->format('Y-m')])) {
                    $months[$due->format('Y-m')]['renewals']++;
                    $months[$due->format('Y-m')]['amount'] += $amount;
                }
            }
        }

        $table = new ReportTable(['month' => ['Month', ReportTable::MONTH], 'renewals' => ['Renewals', ReportTable::NUMBER], 'amount' => ['Amount', ReportTable::MONEY]], currency: $filters->currency);
        $table->chart = 'amount';
        foreach ($months as $month => $values) {
            $table->add(['month' => $month] + $values);
        }

        $byCycle = new ReportTable([
            'cycle' => ['Billing cycle', ReportTable::TEXT],
            'services' => ['Active services', ReportTable::NUMBER],
            'amount' => ['Each renewal', ReportTable::MONEY],
            'monthly' => ['Per month', ReportTable::MONEY],
        ], 'By billing cycle', $filters->currency);
        foreach (BillingCycle::cases() as $cycle) {
            if (isset($cycles[$cycle->value])) {
                $byCycle->add($cycles[$cycle->value]);
            }
        }

        return (new ReportResult)
            ->table($table->withTotals(['month' => null]))
            ->table($byCycle->withTotals(['cycle' => 'Total']))
            ->note('Each active service\'s renewal price, less any recurring discount and before tax, on each due date from today. Services with a cancellation request, suspended services and one-time products are left out. Per month spreads each renewal over its billing cycle.');
    }
}
