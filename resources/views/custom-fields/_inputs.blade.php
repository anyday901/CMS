{{-- $fields, $values (field id => value) --}}
@foreach ($fields as $field)
    @php $value = old("custom_fields.{$field->id}", $values[$field->id] ?? ''); @endphp
    @if ($field->type === 'checkbox')
        <div class="{{ $wide ?? '' }}">
            <input type="hidden" name="custom_fields[{{ $field->id }}]" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="custom_fields[{{ $field->id }}]" value="1" @checked($value === '1')> {{ $field->name }}</label>
            @if ($field->help)<span class="text-ink-500">{{ $field->help }}</span>@endif
        </div>
    @else
        <label class="block {{ $field->type === 'textarea' ? ($wide ?? '') : '' }}">{{ $field->name }}@if ($field->required) <span class="text-ink-500">(required)</span>@endif
            @if ($field->type === 'textarea')
                <textarea name="custom_fields[{{ $field->id }}]" rows="3" @required($field->required) class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ $value }}</textarea>
            @elseif ($field->type === 'dropdown')
                <select name="custom_fields[{{ $field->id }}]" @required($field->required) class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <option value="">{{ $field->required ? 'Choose…' : 'None' }}</option>
                    @foreach ($field->choices() as $choice)
                        <option value="{{ $choice }}" @selected($value === $choice)>{{ $choice }}</option>
                    @endforeach
                </select>
            @else
                <input name="custom_fields[{{ $field->id }}]" value="{{ $value }}" @required($field->required) class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            @endif
            @if ($field->help)<span class="text-ink-500">{{ $field->help }}</span>@endif
        </label>
    @endif
@endforeach
