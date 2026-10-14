@php use App\Models\CustomField; @endphp
<label class="block">For
    <select name="entity" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        @foreach (CustomField::ENTITIES as $value => $label)
            <option value="{{ $value }}" @selected(old('entity', $field->entity) === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>
<label class="block">Product (services only)
    <select name="product_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        <option value="">Every product</option>
        @foreach ($products as $product)
            <option value="{{ $product->id }}" @selected((string) old('product_id', $field->product_id) === (string) $product->id)>{{ $product->name }}</option>
        @endforeach
    </select>
</label>
<label class="block">Department (tickets only)
    <select name="department_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        <option value="">Every department</option>
        @foreach ($departments as $department)
            <option value="{{ $department->id }}" @selected((string) old('department_id', $field->department_id) === (string) $department->id)>{{ $department->name }}</option>
        @endforeach
    </select>
</label>
<label class="block">Name
    <input name="name" value="{{ old('name', $field->name) }}" required maxlength="255" placeholder="VAT number" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="block">Type
    <select name="type" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        @foreach (CustomField::TYPES as $value => $label)
            <option value="{{ $value }}" @selected(old('type', $field->type) === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>
<label class="block">Dropdown choices, one per line
    <textarea name="options" rows="3" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('options', $field->options) }}</textarea>
</label>
<label class="block">Help text (optional)
    <input name="help" value="{{ old('help', $field->help) }}" maxlength="255" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="block">Order
    <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $field->sort_order ?? 0) }}" class="mt-1 w-24 rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="flex items-center gap-2"><input type="checkbox" name="required" value="1" @checked(old('required', $field->required))> Required</label>
<label class="flex items-center gap-2"><input type="checkbox" name="admin_only" value="1" @checked(old('admin_only', $field->admin_only))> Staff only: clients don't see it</label>
