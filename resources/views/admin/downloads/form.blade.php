@php use Illuminate\Support\Number; @endphp
<x-admin-layout :title="$download->exists ? 'Edit download' : 'New download'">
    @include('admin.support._nav')
    <h1 class="mb-6 text-2xl font-semibold">{{ $download->exists ? 'Edit download' : 'New download' }}</h1>
    <form method="POST" action="{{ $download->exists ? route('admin.downloads.update', $download) : route('admin.downloads.store') }}" enctype="multipart/form-data" class="max-w-2xl space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf
        @if ($download->exists) @method('PUT') @endif
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="block sm:col-span-2">Title
                <input name="title" value="{{ old('title', $download->title) }}" required maxlength="255" placeholder="Backup agent for Windows" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Order
                <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $download->sort_order ?? 0) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
        </div>
        <label class="block">Category
            <select name="download_category_id" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) old('download_category_id', $download->download_category_id) === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">Description (optional)
            <textarea name="description" rows="3" maxlength="5000" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('description', $download->description) }}</textarea>
        </label>
        <label for="download-file" class="block">{{ $download->exists ? 'Replace the file (optional)' : 'File' }}
            <input id="download-file" type="file" name="file" @required(! $download->exists) class="mt-1 block w-full">
            <span class="text-ink-500">Up to {{ Number::fileSize(App\Http\Controllers\Admin\DownloadController::maxKb() * 1024) }}, if PHP's upload limits allow it.@if ($download->exists) Now: {{ $download->file_name }} ({{ Number::fileSize($download->size) }}).@endif</span>
        </label>
        <label class="flex items-center gap-2"><input type="checkbox" name="clients_only" value="1" @checked(old('clients_only', $download->clients_only))> Logged-in clients only</label>
        <fieldset>
            <legend class="mb-1">Only for clients with an active service of (none ticked for no product limit)</legend>
            <div class="max-h-40 space-y-1 overflow-y-auto rounded-md p-2 ring-1 ring-ink-200">
                @forelse ($products as $product)
                    <label for="product-{{ $product->id }}" class="flex items-center gap-2"><input id="product-{{ $product->id }}" type="checkbox" name="product_ids[]" value="{{ $product->id }}" @checked(in_array($product->id, array_map('intval', (array) old('product_ids', $download->exists ? $download->products->modelKeys() : []))))> {{ $product->name }}</label>
                @empty
                    <span class="text-ink-500">No products yet.</span>
                @endforelse
            </div>
        </fieldset>
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ $download->exists ? 'Save' : 'Add download' }}</button>
    </form>
    @if ($download->exists)
        <p class="mt-3 text-sm text-ink-500">Downloaded {{ $download->downloads }} {{ Str::plural('time', $download->downloads) }}.</p>
        <form method="POST" action="{{ route('admin.downloads.destroy', $download) }}" class="mt-4" onsubmit="return confirm('Delete this download and its file?')">
            @csrf @method('DELETE')
            <button class="rounded-full px-4 py-2 text-sm text-red-700 ring-1 ring-red-200 hover:bg-red-50">Delete download</button>
        </form>
    @endif
</x-admin-layout>
