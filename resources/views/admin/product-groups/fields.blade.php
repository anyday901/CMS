<label class="block">Name
    <input name="name" value="{{ old('name', $group->name) }}" required placeholder="Web hosting" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="block">Web address (blank to make one from the name)
    <input name="slug" value="{{ old('slug', $group->slug) }}" placeholder="web-hosting" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="block">Description
    <textarea name="description" rows="3" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('description', $group->description) }}</textarea>
</label>
<label class="block">Sort order
    <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $group->sort_order ?? 0) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<input type="hidden" name="visible" value="0">
<label class="flex items-center gap-2"><input type="checkbox" name="visible" value="1" @checked(old('visible', $group->exists ? $group->visible : true))> Show in the store</label>
