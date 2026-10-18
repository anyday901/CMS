@php use App\Support\Money; @endphp
<div class="space-y-4">
    @if ($savedMethods->isNotEmpty())
        <form id="stripe-saved" class="space-y-2">
            <p class="font-medium">{{ __('Pay with a saved card') }}</p>
            @foreach ($savedMethods as $method)
                <label class="flex items-center gap-2">
                    <input type="radio" name="payment_method" value="{{ $method->id }}" @checked($method->is_default || ($loop->first && ! $savedMethods->contains('is_default', true)))>
                    <span>{{ $method->label() }}</span>
                    @if ($method->expiry())<span class="text-ink-500">{{ __('expires :date', ['date' => $method->expiry()]) }}</span>@endif
                </label>
            @endforeach
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500 disabled:opacity-50">{{ __('Pay :amount', ['amount' => Money::format($invoice->balance(), $invoice->currency)]) }}</button>
        </form>
        <p class="text-ink-500">{{ __('Or pay with another card:') }}</p>
    @endif
    <form id="stripe-card" class="space-y-3">
        <div id="stripe-element"></div>
        <label class="flex items-center gap-2">
            <input type="checkbox" id="stripe-save" value="1">
            <span>{{ __('Save this card for future payments') }}</span>
        </label>
        <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500 disabled:opacity-50">{{ __('Pay by card') }}</button>
    </form>
</div>
<script src="https://js.stripe.com/v3/"></script>
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
    const showError = (message) => {
        const el = document.getElementById('payment-error');
        el.textContent = message || @json(__('Something went wrong. Please try again.'));
        el.classList.remove('hidden');
    };
    const post = async (url, body) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body || {}),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message);
        return data;
    };
    const busy = (form, on) => form.querySelectorAll('button').forEach((button) => { button.disabled = on; });
    const finish = async (intentId) => {
        window.location = (await post(@json(route('portal.pay.stripe.confirm', $invoice)), { payment_intent: intentId })).redirect;
    };

    let stripe;
    try {
        stripe = Stripe(@json($publishableKey));
    } catch (e) {
        showError(@json(__('Card payments are unavailable right now. Please choose another way to pay.')));
        return;
    }

    const elements = stripe.elements({ mode: 'payment', amount: @json($stripeAmount), currency: @json(strtolower($invoice->currency)) });
    elements.create('payment').mount('#stripe-element');

    const save = document.getElementById('stripe-save');
    save.addEventListener('change', () => elements.update({ setupFutureUsage: save.checked ? 'off_session' : null }));

    const card = document.getElementById('stripe-card');
    card.addEventListener('submit', async (event) => {
        event.preventDefault();
        busy(card, true);
        try {
            const { error: invalid } = await elements.submit();
            if (invalid) throw new Error(invalid.message);
            const { client_secret: clientSecret } = await post(@json(route('portal.pay.stripe.intent', $invoice)), { save: save.checked });
            const { error, paymentIntent } = await stripe.confirmPayment({
                elements,
                clientSecret,
                confirmParams: { return_url: @json(route('portal.pay.stripe.return', $invoice)) },
                redirect: 'if_required',
            });
            if (error) throw new Error(error.message);
            await finish(paymentIntent.id);
        } catch (e) {
            showError(e.message);
            busy(card, false);
        }
    });

    const saved = document.getElementById('stripe-saved');
    saved?.addEventListener('submit', async (event) => {
        event.preventDefault();
        busy(saved, true);
        try {
            const method = saved.querySelector('input[name="payment_method"]:checked')?.value;
            const result = await post(@json(route('portal.pay.stripe.saved', $invoice)), { payment_method: method });
            if (!result.client_secret) {
                window.location = result.redirect;
                return;
            }
            // The bank wants the client to confirm the payment, such as with 3-D Secure.
            const { error, paymentIntent } = await stripe.handleNextAction({ clientSecret: result.client_secret });
            if (error) throw new Error(error.message);
            await finish(paymentIntent.id);
        } catch (e) {
            showError(e.message);
            busy(saved, false);
        }
    });
})();
</script>
