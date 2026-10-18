@php $input = 'mt-1 block w-full rounded-md px-3 py-2 font-mono ring-1 ring-ink-300'; @endphp
<x-admin-layout title="Payment gateways">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Payment gateways</h1>
        <a href="{{ route('admin.automation.edit') }}" class="ml-auto text-sm text-brand-600">Automatic charging</a>
        <a href="{{ route('admin.settings.edit') }}" class="text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">How clients pay invoices online. Each gateway set up here adds a way to pay on unpaid invoices in the client area.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <form method="POST" action="{{ route('admin.payment-gateways.update') }}" class="space-y-6 rounded-lg border border-ink-200 bg-white p-6 text-sm md:col-span-2">
            @csrf @method('PUT')

            <fieldset class="space-y-3">
                <legend class="mb-1 flex items-center gap-2 text-base font-semibold">Stripe
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $stripe->isEnabled() ? 'bg-green-100 text-green-800' : 'bg-ink-100 text-ink-600' }}">{{ $stripe->isEnabled() ? 'On' : 'Off' }}</span>
                </legend>
                <p class="text-ink-600">Card payments, saved cards, and automatic charging of saved cards when invoices fall due. Find the keys in the Stripe dashboard under <strong>Developers &gt; API keys</strong>. Test keys take only Stripe's test cards.</p>
                <label class="block">Publishable key
                    <input name="publishable_key" value="{{ old('publishable_key', $publishableKey) }}" maxlength="255" placeholder="pk_live_..." class="{{ $input }}" autocomplete="off">
                </label>
                @foreach (['secret_key' => ['Secret key', 'sk_live_...'], 'webhook_secret' => ['Webhook signing secret', 'whsec_...']] as $field => [$label, $placeholder])
                    <label class="block">{{ $label }}
                        <input type="password" name="{{ $field }}" maxlength="255" autocomplete="new-password" placeholder="{{ $hasSecret[$field] ? 'Saved; leave blank to keep it' : $placeholder }}" class="{{ $input }}">
                    </label>
                    @if ($hasSecret[$field])
                        <label class="flex items-center gap-2"><input type="hidden" name="forget_{{ $field }}" value="0"><input type="checkbox" name="forget_{{ $field }}" value="1"> Remove the saved {{ strtolower($label) }}</label>
                    @endif
                @endforeach
                <p class="text-ink-500">Secrets are kept encrypted. Saved values win over <code class="rounded bg-ink-100 px-1">STRIPE_*</code> in <code class="rounded bg-ink-100 px-1">.env</code>.</p>
                <div class="rounded-md bg-ink-50 p-3">
                    <p class="mb-1 font-medium">Webhook</p>
                    <p class="text-ink-600">In Stripe, under <strong>Developers &gt; Webhooks</strong>, add an endpoint for</p>
                    <p class="my-1 font-mono break-all">{{ route('webhooks.stripe') }}</p>
                    <p class="text-ink-600">with these events, then copy its signing secret above: <code class="text-xs">payment_intent.succeeded</code>, <code class="text-xs">payment_intent.payment_failed</code>, <code class="text-xs">charge.refunded</code>, <code class="text-xs">charge.refund.updated</code>, <code class="text-xs">charge.dispute.created</code>, <code class="text-xs">charge.dispute.updated</code>, <code class="text-xs">charge.dispute.closed</code>, <code class="text-xs">setup_intent.succeeded</code> and <code class="text-xs">payment_method.detached</code>.</p>
                </div>
            </fieldset>

            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save payment gateway settings</button>
        </form>

        <div class="space-y-3 self-start rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <h2 class="text-base font-semibold">Other gateways</h2>
            <p class="text-ink-600">Set up in <code class="rounded bg-ink-100 px-1">.env</code> on the server; see the README.</p>
            @foreach ($others as $other)
                <div>
                    <div class="flex items-center gap-2 font-medium">{{ $other['label'] }}
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $other['enabled'] ? 'bg-green-100 text-green-800' : 'bg-ink-100 text-ink-600' }}">{{ $other['enabled'] ? 'On' : 'Off' }}</span>
                    </div>
                    <div class="text-xs text-ink-500">{{ $other['env'] }}</div>
                </div>
            @endforeach
            <p class="border-t border-ink-100 pt-3 text-ink-600">Bank transfer and mail-in payments are set up with their instructions on the <a href="{{ route('admin.settings.edit') }}#offline-payments" class="text-brand-600">Settings</a> page.</p>
        </div>
    </div>
</x-admin-layout>
