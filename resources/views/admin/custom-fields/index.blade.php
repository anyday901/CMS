@php use App\Models\CustomField; @endphp
<x-admin-layout title="Custom fields">
    <h1 class="mb-2 text-2xl font-semibold">Custom fields</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Extra details to keep on clients and services, such as a VAT number or a server hostname. Client fields show on the client's page and, unless staff only, on their portal account page where they can fill them in. Service fields show on the service page and, unless staff only, to the client read-only. They're also sent to provisioning modules. Ticket fields are asked for when a client opens a ticket in their department, and staff see and edit them on the ticket.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="space-y-6 md:col-span-2">
            @foreach (['Client fields' => $clientFields, 'Service fields' => $serviceFields, 'Ticket fields' => $ticketFields] as $heading => $list)
                <div>
                    <h2 class="mb-2 font-semibold">{{ $heading }}</h2>
                    @if ($list->isEmpty())
                        <p class="text-sm text-ink-500">None yet.</p>
                    @else
                        <div class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
                            @foreach ($list as $item)
                                <div class="flex items-center gap-4 px-4 py-3">
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('admin.custom-fields.edit', $item) }}" class="font-medium text-brand-600">{{ $item->name }}</a>
                                        <span class="text-ink-500">{{ CustomField::TYPES[$item->type] }}{{ match ($item->entity) { 'service' => ' · '.($item->product?->name ?? 'Every product'), 'ticket' => ' · '.($item->department?->name ?? 'Every department'), default => '' } }}{{ $item->required ? ' · required' : '' }}{{ $item->admin_only ? ' · staff only' : '' }}</span>
                                    </div>
                                    <form method="POST" action="{{ route('admin.custom-fields.destroy', $item) }}" onsubmit="return confirm('Delete this field? Saved values will no longer show.')">
                                        @csrf @method('DELETE')
                                        <button class="rounded-full px-3 py-1 text-ink-600 ring-1 ring-ink-300 hover:bg-ink-100">Delete</button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('admin.custom-fields.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a custom field</h2>
            @include('admin.custom-fields.fields')
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add field</button>
        </form>
    </div>
</x-admin-layout>
