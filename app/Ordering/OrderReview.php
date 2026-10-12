<?php

namespace App\Ordering;

use App\Billing\InvoiceCanceller;
use App\Billing\ServiceLifecycle;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Staff decisions on new orders in the pending orders queue. */
class OrderReview
{
    public function __construct(private ServiceLifecycle $lifecycle, private InvoiceCanceller $invoices) {}

    /**
     * Accepts a pending order. Services of a paid order held for review are
     * activated now; the rest activate when the invoice is paid, as usual.
     */
    public function accept(Order $order, User $staff): void
    {
        DB::transaction(function () use ($order, $staff) {
            $order = $this->lockOrder($order);
            // The invoice lock is the one PaymentRecorder takes, so a payment
            // landing now either activates the services itself or is seen here.
            $invoice = $order->invoice_id ? Invoice::lockForUpdate()->find($order->invoice_id) : null;

            if (! $order->isPending()) {
                throw new InvalidArgumentException("Order #{$order->id} has already been {$this->done($order)}.");
            }

            $this->close($order, OrderStatus::Active, $staff);
            Activity::record("Accepted order #{$order->id}", $order, [], $order->client);

            if ($invoice?->status === InvoiceStatus::Paid) {
                foreach ($order->services()->where('status', ServiceStatus::Pending)->orderBy('id')->get() as $service) {
                    $this->lifecycle->activate($service);
                }
            }
        });
    }

    /**
     * Cancels an order, or marks it as fraud: its unpaid invoice is
     * cancelled, services not started yet are cancelled and running ones
     * terminated, and the stock and promotion code use it took are given back.
     * A paid invoice is left for staff to refund.
     *
     * @return bool Whether the invoice had been paid.
     *
     * @throws InvalidArgumentException when the order is already closed or its invoice is part paid.
     */
    public function cancel(Order $order, User $staff, bool $fraud = false): bool
    {
        return DB::transaction(function () use ($order, $staff, $fraud) {
            $order = $this->lockOrder($order);
            $invoice = $order->invoice_id ? Invoice::lockForUpdate()->find($order->invoice_id) : null;

            if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Fraud], true)) {
                throw new InvalidArgumentException("Order #{$order->id} has already been {$this->done($order)}.");
            }

            $open = in_array($invoice?->status, [InvoiceStatus::Draft, InvoiceStatus::Unpaid], true);
            if ($open && $invoice->amountPaid() !== 0) {
                throw new InvalidArgumentException("Invoice #{$invoice->number} has a payment on it. Refund it from the invoice first.");
            }

            $services = $order->services()
                ->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended])
                ->with('product')
                ->orderBy('id')
                ->get();

            if ($open) {
                $this->invoices->cancel($invoice);
            }
            $this->stopServices($services);
            $this->giveBack($order, $services->pluck('product_id')->all());

            $this->close($order, $fraud ? OrderStatus::Fraud : OrderStatus::Cancelled, $staff);
            Activity::record($fraud ? "Marked order #{$order->id} as fraud" : "Cancelled order #{$order->id}", $order, [], $order->client);

            return $invoice?->status === InvoiceStatus::Paid;
        });
    }

    private function lockOrder(Order $order): Order
    {
        return Order::lockForUpdate()->with('client')->findOrFail($order->id);
    }

    private function close(Order $order, OrderStatus $status, User $staff): void
    {
        $order->forceFill(['status' => $status, 'reviewed_at' => now(), 'reviewed_by' => $staff->id])->save();
    }

    private function done(Order $order): string
    {
        return match ($order->status) {
            OrderStatus::Active => 'accepted',
            OrderStatus::Fraud => 'marked as fraud',
            default => 'cancelled',
        };
    }

    /**
     * Cancels services that never started and terminates running ones.
     *
     * @param  iterable<Service>  $services
     */
    private function stopServices(iterable $services): void
    {
        foreach ($services as $service) {
            // Fresh, since cancelling the invoice or terminating its main service may have stopped it.
            $service->refresh();

            match ($service->status) {
                ServiceStatus::Pending => $this->cancelService($service),
                ServiceStatus::Active, ServiceStatus::Suspended => $this->lifecycle->terminate($service),
                default => null,
            };
        }
    }

    private function cancelService(Service $service): void
    {
        $service->update(['status' => ServiceStatus::Cancelled]);
        Activity::record("Cancelled {$service->description()}", $service);
    }

    /**
     * Puts back the stock the order's services took and its promotion code use.
     *
     * @param  list<int>  $productIds  One entry per service stopped.
     */
    private function giveBack(Order $order, array $productIds): void
    {
        foreach (array_count_values($productIds) as $productId => $quantity) {
            Product::whereKey($productId)->where('stock_control', true)->increment('stock_quantity', $quantity);
        }

        if ($order->promotion_id !== null) {
            Promotion::whereKey($order->promotion_id)->where('uses', '>', 0)->decrement('uses');
        }
    }
}
