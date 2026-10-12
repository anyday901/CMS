<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Order;
use App\Ordering\OrderReview;
use Illuminate\Database\Eloquent\Builder;
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
        $search = trim((string) $request->query('q'));
        $client = $request->filled('client') ? Client::find((int) $request->query('client')) : null;
        // Looking someone up searches every order unless a status is picked.
        $default = $search !== '' || $client ? 'all' : OrderStatus::Pending->value;
        $status = OrderStatus::tryFrom((string) $request->query('status', $default));

        $orders = Order::query()
            ->with(['client', 'invoice', 'services.product'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($client, fn ($query) => $query->where('client_id', $client->id))
            ->when($search !== '', fn ($query) => $this->search($query, $search))
            // The queue is worked oldest first; everything else newest first.
            ->when($status === OrderStatus::Pending, fn ($query) => $query->oldest('id'), fn ($query) => $query->latest('id'))
            ->paginate(50)
            ->withQueryString();

        return view('admin.orders.index', compact('orders', 'status', 'search', 'client'));
    }

    /** Matches an order number, or the client's name, company or email. */
    private function search(Builder $query, string $search): void
    {
        $number = ltrim($search, '#');

        $query->where(fn (Builder $query) => $query
            ->when(ctype_digit($number), fn ($query) => $query->whereKey((int) $number))
            ->orWhereHas('client', fn (Builder $clients) => $clients
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('company', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")));
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
