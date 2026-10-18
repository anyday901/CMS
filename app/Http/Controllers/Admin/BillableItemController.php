<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\BillableItem;
use App\Models\Client;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Billable items on a client's page. Staff change them until they're invoiced. */
class BillableItemController extends Controller
{
    private const TAB = 'billable-items';

    public function store(Request $request, Client $client): RedirectResponse
    {
        $item = $client->billableItems()->make($this->validated($request));
        $item->user()->associate($request->user());
        $item->save();
        Activity::record("Added billable item \"{$item->description}\" for ".Money::format($item->amount, $client->currency), $client);

        return $this->back($client, 'Billable item added.');
    }

    public function update(Request $request, BillableItem $item): RedirectResponse
    {
        if ($item->isInvoiced()) {
            throw ValidationException::withMessages(['item' => 'This item has been invoiced, so it can no longer be changed.']);
        }

        $item->fill($this->validated($request));

        // A one-off item whose invoice was cancelled is billed again by its new action.
        if (! $item->isRecurring() && $item->invoice_id !== null) {
            $item->forceFill(['invoice_id' => null, 'invoiced_count' => 0]);
        }

        $item->save();
        Activity::record("Edited billable item \"{$item->description}\"", $item->client);

        return $this->back($item->client, 'Billable item saved.');
    }

    public function destroy(BillableItem $item): RedirectResponse
    {
        if ($item->isInvoiced()) {
            throw ValidationException::withMessages(['item' => 'This item has been invoiced, so it can no longer be deleted.']);
        }

        $item->delete();
        Activity::record("Deleted billable item \"{$item->description}\"", $item->client);

        return $this->back($item->client, 'Billable item deleted.');
    }

    private function back(Client $client, string $status): RedirectResponse
    {
        return redirect()->route('admin.clients.show', [$client, 'tab' => self::TAB])->with('status', $status);
    }

    /** The item's fields, with the amount worked out from hours and rate when hours are given. */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:200'],
            'hours' => ['nullable', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'rate' => ['nullable', 'required_with:hours', Money::rule()],
            'amount' => ['nullable', 'required_without:hours', Money::rule()],
            'invoice_action' => ['required', Rule::in(array_keys(BillableItem::ACTIONS))],
            'due_date' => ['nullable', 'required_if:invoice_action,'.BillableItem::DUE_DATE.','.BillableItem::RECUR, 'date'],
            'recur_every' => ['nullable', 'required_if:invoice_action,'.BillableItem::RECUR, 'integer', 'min:1', 'max:999'],
            'recur_unit' => ['nullable', 'required_if:invoice_action,'.BillableItem::RECUR, Rule::in(array_keys(BillableItem::UNITS))],
            'recur_times' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ], ['hours.regex' => 'Enter the hours as a number like 1.5.']);

        $hours = filled($data['hours'] ?? null) ? $data['hours'] : null;
        $rate = $hours !== null ? Money::parse($data['rate']) : null;
        $amount = $hours !== null ? BillableItem::cost($hours, $rate) : Money::parse($data['amount']);

        if ($amount <= 0) {
            throw ValidationException::withMessages([$hours !== null ? 'rate' : 'amount' => 'The amount must be more than zero.']);
        }

        $recurs = $data['invoice_action'] === BillableItem::RECUR;

        return [
            'description' => $data['description'],
            'hours' => $hours,
            'rate' => $rate,
            'amount' => $amount,
            'taxable' => $request->boolean('taxable'),
            'invoice_action' => $data['invoice_action'],
            'due_date' => $data['due_date'] ?? null,
            'recur_every' => $recurs ? (int) $data['recur_every'] : null,
            'recur_unit' => $recurs ? $data['recur_unit'] : null,
            'recur_times' => $recurs && filled($data['recur_times'] ?? null) ? (int) $data['recur_times'] : null,
        ];
    }
}
