{{-- $fields, $values: one grid cell per field --}}
@foreach ($fields as $field)
    <div class="{{ $field->type === 'textarea' ? ($wide ?? '') : '' }}"><div class="text-ink-500">{{ $field->name }}@if ($field->admin_only && ($showAdminOnly ?? false)) <span class="text-xs">{{ __('(staff only)') }}</span>@endif</div>{!! nl2br(e($field->display($values[$field->id] ?? null))) !!}</div>
@endforeach
