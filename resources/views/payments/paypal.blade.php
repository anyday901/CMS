<div id="paypal-buttons"></div>
<script src="{{ $sdkUrl }}"></script>
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
    const showError = (message) => {
        const el = document.getElementById('payment-error');
        el.textContent = message || 'Something went wrong. Please try again.';
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

    paypal.Buttons({
        style: { layout: 'vertical', shape: 'rect', label: 'pay' },
        createOrder: async () => (await post(@json(route('portal.pay.paypal.order', $invoice)))).id,
        onApprove: async (data) => {
            const result = await post(@json(route('portal.pay.paypal.capture', $invoice)), { order_id: data.orderID });
            window.location = result.redirect;
        },
        onError: (err) => showError(err?.message),
    }).render('#paypal-buttons');
})();
</script>
