{{-- The Affiliate box on a client's page: whether they are an affiliate, and who referred them. --}}
@php $referrer = $client->referral?->affiliate; @endphp
<div id="affiliate" class="mb-8 rounded-lg border border-ink-200 bg-white p-4 text-sm">
    <h2 class="mb-2 font-semibold">Affiliate</h2>
    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <div class="text-ink-500">Affiliate</div>
            @if ($client->affiliate)
                Yes, code <span class="font-mono">{{ $client->affiliate->code }}</span>@unless ($client->affiliate->isActive()) (turned off)@endunless
                @can('manage-billing')· <a href="{{ route('admin.affiliates.show', $client->affiliate) }}" class="text-brand-600">referrals and commissions</a>@endcan
            @else
                Not an affiliate
                @can('manage-billing')
                    <form method="POST" action="{{ route('admin.affiliates.store') }}" class="mt-1">@csrf
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <button class="rounded-md px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Make affiliate</button>
                    </form>
                @endcan
            @endif
        </div>
        <div>
            <div class="text-ink-500">Referred by</div>
            @if ($referrer)
                @can('manage-billing')<a href="{{ route('admin.affiliates.show', $referrer) }}" class="text-brand-600">{{ $referrer->client->fullName() }}</a>@else{{ $referrer->client->fullName() }}@endcan
                <span class="text-ink-500">({{ $referrer->code }}), since {{ $client->referral->created_at->format('M j, Y') }}</span>
            @else
                Nobody
            @endif
            @can('manage-billing')
                <details class="mt-1" @if ($errors->has('referrer_code')) open @endif>
                    <summary class="cursor-pointer text-brand-600">{{ $referrer ? 'Change referrer' : 'Set referrer' }}</summary>
                    <form method="POST" action="{{ route('admin.affiliates.referrer', $client) }}" class="mt-2 flex flex-wrap items-end gap-2">
                        @csrf @method('PUT')
                        <label class="block">Affiliate code
                            <input name="referrer_code" value="{{ old('referrer_code', $referrer?->code) }}" maxlength="40" placeholder="Blank for nobody" class="mt-1 block w-40 rounded-md px-2 py-1 font-mono ring-1 ring-ink-300">
                        </label>
                        <button class="rounded-md px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Save</button>
                        <span class="basis-full text-ink-500">Commissions already earned stay with the affiliate who earned them.</span>
                    </form>
                </details>
            @endcan
        </div>
    </div>
</div>
