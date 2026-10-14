<label class="block">Name
    <input name="name" value="{{ old('name', $category->name) }}" required maxlength="255" placeholder="Getting started" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="block">Description (optional)
    <textarea name="description" rows="2" maxlength="2000" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('description', $category->description) }}</textarea>
</label>
<label class="block">Inside
    <select name="parent_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        <option value="">Nothing: a top-level category</option>
        @foreach ($parents as $parent)
            <option value="{{ $parent->id }}" @selected((string) old('parent_id', $category->parent_id) === (string) $parent->id)>{{ $parent->name }}</option>
        @endforeach
    </select>
</label>
<label class="block">Order
    <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $category->sort_order ?? 0) }}" class="mt-1 w-24 rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="flex items-center gap-2"><input type="checkbox" name="hidden" value="1" @checked(old('hidden', $category->exists && ! $category->visible))> Hidden: clients don't see it or its articles</label>
