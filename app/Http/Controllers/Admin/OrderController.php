<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Ordering\OrderReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Orders from the store, with the pending ones first in line for review. */
class OrderController extends Controller
{
    public function __construct(private OrderReview $review) {}

    public function index(Request $request): View
    {
        $status = OrderStatus::tryFrom((string) $request->query('status', OrderStatus::Pending->value));

        $orders = Order::query()
            ->with(['client', 'invoice', 'services.product'])
            ->when($status, fn ($query) => $query->where('status', $status))
            // The queue is worked oldest first; everything else newest first.
            ->when($status === OrderStatus::Pending, fn ($query) => $query->oldest('id'), fn ($query) => $query->latest('id'))
            ->paginate(50)
            ->withQueryString();

        return view('admin.orders.index', ['orders' => $orders, 'status' => $status]);
    }

    public function show(Order $order): View
    {
        $order->load(['client', 'invoice', 'promotion', 'reviewer', 'services' => fn ($query) => $query->with('product')->orderBy('id')]);

        return view('admin.orders.show', compact('order'));
    }

    public function accept(Request $request, Order $order): RedirectResponse
    {
        $this->attempt(fn () => $this->review->accept($order, $request->user()));

        return $this->backTo($order, "Order #{$order->id} accepted.");
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        return $this->close($request, $order, false);
    }

    public function fraud(Request $request, Order $order): RedirectResponse
    {
        return $this->close($request, $order, true);
    }

    private function close(Request $request, Order $order, bool $fraud): RedirectResponse
    {
        $paid = $this->attempt(fn () => $this->review->cancel($order, $request->user(), $fraud));
        $message = ($fraud ? "Order #{$order->id} marked as fraud." : "Order #{$order->id} cancelled.")
            .($paid ? ' Its invoice was paid, so refund it from the invoice if the money should go back.' : '');

        return $this->backTo($order, $message);
    }

    private function backTo(Order $order, string $message): RedirectResponse
    {
        return redirect()->route('admin.orders.show', $order)->with('status', $message);
    }

    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }
    }
}
