<x-portal-layout :title="$group->name" :store="true">
    <p class="mb-2 text-sm"><a href="{{ route('store.index') }}" class="text-brand-600">{{ __('All products') }}</a></p>
    <h1 class="mb-2 text-2xl font-semibold">{{ $group->name }}</h1>
    @if ($group->description)<p class="mb-6 max-w-2xl text-sm text-ink-600">{{ $group->description }}</p>@endif
    @if ($products->isEmpty())
        <p class="text-sm text-ink-500">{{ __('There is nothing to order in this group right now.') }}</p>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($products as $product)
                @include('store._product-card')
            @endforeach
        </div>
    @endif
</x-portal-layout>
