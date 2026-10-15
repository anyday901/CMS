<x-portal-layout :title="__('Products')" :store="true">
    <h1 class="mb-2 text-2xl font-semibold">{{ __('Products') }}</h1>
    <p class="mb-8 text-sm text-ink-600">{{ __('Pick a product to choose its options. You can create an account when you check out.') }}</p>

    @if ($groups->isEmpty() && $ungrouped->isEmpty())
        <p class="text-sm text-ink-500">{{ __('There is nothing to order right now.') }}</p>
    @endif

    @foreach ($groups as $group)
        <section class="mb-10">
            <div class="mb-3 flex items-baseline gap-3">
                <h2 class="text-lg font-semibold"><a href="{{ route('store.group', $group) }}" class="hover:text-brand-700">{{ $group->name }}</a></h2>
            </div>
            @if ($group->description)<p class="mb-4 max-w-2xl text-sm text-ink-600">{{ $group->description }}</p>@endif
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($group->products as $product)
                    @include('store._product-card')
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($ungrouped->isNotEmpty())
        <section class="mb-10">
            @if ($groups->isNotEmpty())<h2 class="mb-3 text-lg font-semibold">{{ __('More products') }}</h2>@endif
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($ungrouped as $product)
                    @include('store._product-card')
                @endforeach
            </div>
        </section>
    @endif
</x-portal-layout>
