<form id="stripe-add-card" class="space-y-3">
    <div id="stripe-card-element"></div>
    <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500 disabled:opacity-50">{{ __('Save card') }}</button>
    <p id="stripe-card-error" class="hidden rounded-md bg-red-50 px-3 py-2 text-red-800" role="alert"></p>
</form>
<script src="https://js.stripe.com/v3/"></script>
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
    const form = document.getElementById('stripe-add-card');
    const showError = (message) => {
        const el = document.getElementById('stripe-card-error');
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

    let stripe;
    try {
        stripe = Stripe(@json($publishableKey));
    } catch (e) {
        showError(@json(__('Cards cannot be added right now. Please try again later.')));
        return;
    }

    const elements = stripe.elements({ mode: 'setup', currency: @json($currency), paymentMethodTypes: ['card'] });
    elements.create('payment').mount('#stripe-card-element');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        form.querySelector('button').disabled = true;
        try {
            const { error: invalid } = await elements.submit();
            if (invalid) throw new Error(invalid.message);
            const { client_secret: clientSecret } = await post(@json(route('portal.payment-methods.stripe.setup')));
            const { error, setupIntent } = await stripe.confirmSetup({
                elements,
                clientSecret,
                confirmParams: { return_url: @json(route('portal.payment-methods.stripe.return')) },
                redirect: 'if_required',
            });
            if (error) throw new Error(error.message);
            window.location = (await post(@json(route('portal.payment-methods.stripe.confirm')), { setup_intent: setupIntent.id })).redirect;
        } catch (e) {
            showError(e.message);
            form.querySelector('button').disabled = false;
        }
    });
})();
</script>
