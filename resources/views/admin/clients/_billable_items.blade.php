{{-- The billable items section of the client page: charges the billing run puts on the client's invoices. --}}
@php
    use App\Enums\InvoiceStatus;
    use App\Models\BillableItem;
    use App\Support\Money;
    $fields = ['description', 'amount', 'hours', 'rate', 'invoice_action', 'due_date', 'recur_every', 'recur_unit', 'recur_times'];
@endphp
<h2 id="billable-items" class="mb-3 text-lg font-semibold">Billable items</h2>
@error('item')<p class="mb-3 text-sm text-red-700">{{ $message }}</p>@enderror
@can('manage-billing')
    <details class="mb-4 text-sm" @if ($errors->hasAny($fields)) open @endif>
        <summary class="cursor-pointer text-brand-600">Add billable item</summary>
        <form method="POST" action="{{ route('admin.billable-items.store', $client) }}" class="mt-2 rounded-lg border border-dashed border-ink-300 bg-white p-4">
            @csrf
            @include('admin.clients._billable_item_fields', ['item' => new BillableItem])
            @foreach ($fields as $field)
                @error($field)<p class="mt-1 text-red-700">{{ $message }}</p>@enderror
            @endforeach
            <p class="mt-2 text-ink-500">Give an amount, or hours and an hourly rate to work it out. Invoicing on a due date, or recurring from it, needs the due date. Leave Times empty to recur until stopped.</p>
            <button class="mt-2 rounded-full bg-brand-600 px-3 py-1 font-medium text-white hover:bg-brand-500">Add item</button>
        </form>
    </details>
@endcan
@if ($client->billableItems->isEmpty())
    <p class="text-sm text-ink-500">No billable items.</p>
@else
    <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Description</th><th class="px-4 py-2">Added</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2">Invoicing</th><th class="px-4 py-2">Invoiced</th></tr></thead>
            <tbody class="divide-y divide-ink-100">
                @foreach ($client->billableItems as $item)
                    <tr class="align-top">
                        <td class="px-4 py-2">
                            {{ $item->description }}
                            @if ($item->hours !== null)<div class="text-xs text-ink-500">{{ $item->hours }} hours at {{ Money::format($item->rate, $client->currency) }}/hr</div>@endif
                            @if (! $item->isInvoiced())
                                @can('manage-billing')
                                    <details class="mt-1">
                                        <summary class="cursor-pointer text-brand-600">Edit</summary>
                                        <form method="POST" action="{{ route('admin.billable-items.update', $item) }}" class="mt-2 min-w-[40rem] space-y-2">
                                            @csrf @method('PUT')
                                            @include('admin.clients._billable_item_fields')
                                            <div class="flex items-center gap-3">
                                                <button class="rounded-md px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Save</button>
                                                <button form="delete-billable-item-{{ $item->id }}" class="text-red-600 hover:underline">Delete</button>
                                            </div>
                                        </form>
                                        <form id="delete-billable-item-{{ $item->id }}" method="POST" action="{{ route('admin.billable-items.destroy', $item) }}" onsubmit="return confirm('Delete this billable item?')">@csrf @method('DELETE')</form>
                                    </details>
                                @endcan
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 text-ink-500">{{ $item->created_at->format('M j, Y') }}@if ($item->user)<div class="text-xs">{{ $item->user->name }}</div>@endif</td>
                        <td class="px-4 py-2 text-right">{{ Money::format($item->amount, $client->currency) }}@if ($item->taxable)<div class="text-xs text-ink-500">taxable</div>@endif</td>
                        <td class="px-4 py-2">
                            {{ BillableItem::ACTIONS[$item->invoice_action] ?? $item->invoice_action }}
                            @if ($item->isRecurring())
                                <div class="text-xs text-ink-500">{{ $item->recurrence() }}@unless ($item->isFinished()), next due {{ $item->due_date->format('M j, Y') }}@endunless</div>
                            @elseif ($item->invoice_action === BillableItem::DUE_DATE && $item->due_date)
                                <div class="text-xs text-ink-500">Due {{ $item->due_date->format('M j, Y') }}</div>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-2">
                            @if ($item->invoice)
                                <a href="{{ route('admin.invoices.show', $item->invoice) }}" class="text-brand-600">Invoice #{{ $item->invoice->number }}</a>
                                @if ($item->isRecurring())<div class="text-xs text-ink-500">Last invoiced</div>@elseif ($item->invoice->status === InvoiceStatus::Cancelled)<div class="text-xs text-red-700">Cancelled; save the item to bill it again</div>@endif
                            @else
                                <span class="text-ink-500">Not yet</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
