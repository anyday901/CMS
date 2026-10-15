@props(['group'])
@if ($group)
    <span {{ $attributes->class('inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold ring-1 ring-ink-200') }}><span class="size-2 rounded-full" style="background-color: {{ $group->colour }}" aria-hidden="true"></span>{{ $group->name }}</span>
@endif
