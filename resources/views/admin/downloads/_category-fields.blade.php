<label class="block">Name
    <input name="name" value="{{ old('name', $category->name) }}" required maxlength="255" placeholder="Software" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="block">Description (optional)
    <textarea name="description" rows="2" maxlength="2000" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('description', $category->description) }}</textarea>
</label>
<label class="block">Order
    <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $category->sort_order ?? 0) }}" class="mt-1 w-24 rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
