<?php

namespace App\Reports\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Models\Promotion;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Reports\Sql;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** How often each promotion code was used on orders, and what those orders were worth. */
class PromotionUsage extends Report
{
    public function key(): string
    {
        return 'promotion-usage';
    }

    public function title(): string
    {
        return 'Promotion usage';
    }

    public function group(): string
    {
        return ReportRegistry::SALES;
    }

    public function description(): string
    {
        return 'Orders placed with each promotion code, what they were worth, and the services still on it.';
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $orders = DB::table('orders')
            ->join('clients', 'clients.id', '=', 'orders.client_id')
            ->leftJoin('invoices', fn ($join) => $join->on('invoices.id', '=', 'orders.invoice_id')->where('invoices.status', '!=', InvoiceStatus::Cancelled->value))
            ->whereNotNull('orders.promotion_code')
            ->where('clients.currency', $filters->currency)
            ->whereBetween('orders.created_at', $filters->between())
            ->groupBy('orders.promotion_code')
            ->get([
                'orders.promotion_code as code',
                DB::raw('COUNT(*) as orders'),
                DB::raw("SUM(CASE WHEN orders.status = '".OrderStatus::Active->value."' THEN 1 ELSE 0 END) as accepted"),
                DB::raw('COALESCE(SUM(invoices.total), 0) as value'),
            ]);

        $promotions = Promotion::whereIn('code', $orders->pluck('code'))->get()->keyBy(fn (Promotion $promotion) => strtolower($promotion->code));
        $active = Sql::by(DB::table('services')->join('promotions', 'promotions.id', '=', 'services.promotion_id')
            ->whereIn('services.status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value]), 'promotions.code', 'COUNT(*)')
            ->mapWithKeys(fn ($count, $code) => [strtolower($code) => (int) $count]);

        $table = new ReportTable([
            'code' => ['Code', ReportTable::TEXT],
            'discount' => ['Discount', ReportTable::TEXT],
            'orders' => ['Orders', ReportTable::NUMBER],
            'accepted' => ['Accepted', ReportTable::NUMBER],
            'value' => ['Order value', ReportTable::MONEY],
            'services' => ['Services on it now', ReportTable::NUMBER],
            'uses' => ['Uses, all time', ReportTable::NUMBER],
        ], currency: $filters->currency);
        $table->chart = 'orders';
        $table->empty = 'No orders used a promotion code in these dates.';

        foreach ($orders->sortByDesc('orders') as $row) {
            $promotion = $promotions->get(strtolower($row->code));
            $table->add([
                'code' => $row->code,
                'discount' => $promotion ? $this->discount($promotion, $filters->currency) : 'Code since deleted',
                'orders' => (int) $row->orders,
                'accepted' => (int) $row->accepted,
                'value' => (int) $row->value,
                'services' => $active[strtolower($row->code)] ?? 0,
                'uses' => $promotion?->uses,
            ]);
        }

        return (new ReportResult)->table($table->withTotals(['code' => 'Total']))
            ->note('Order value is the total of each order\'s first invoice, after the discount. Services on it now counts active and suspended services still getting the code\'s recurring discount.');
    }

    private function discount(Promotion $promotion, string $currency): string
    {
        $amount = $promotion->type === Promotion::PERCENT
            ? rtrim(rtrim(number_format($promotion->value / 100, 2, '.', ''), '0'), '.').'%'
            : Money::format($promotion->value, $currency);

        return $amount.($promotion->recurring ? ', recurring' : ', first payment');
    }
}
