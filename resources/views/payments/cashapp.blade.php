@php use App\Support\Money; @endphp
<div id="cash-app-pay"></div>
<script src="{{ $sdkUrl }}"></script>
<script>
(async () => {
    const csrf = '{{ csrf_token() }}';
    const showError = (message) => {
        const el = document.getElementById('payment-error');
        el.textContent = message || 'Something went wrong. Please try again.';
        el.classList.remove('hidden');
    };

    try {
        const payments = Square.payments(@json($applicationId), @json($locationId));
        const request = payments.paymentRequest({
            countryCode: 'US',
            currencyCode: @json($invoice->currency),
            total: { amount: @json(Money::toInput($invoice->balance())), label: 'Total' },
        });
        const cashAppPay = await payments.cashAppPay(request, {
            redirectURL: window.location.href,
            referenceId: @json((string) $invoice->id),
        });

        cashAppPay.addEventListener('ontokenization', async (event) => {
            const { tokenResult, error } = event.detail;
            if (error || tokenResult.status !== 'OK') {
                showError('Cash App did not complete the payment.');
                return;
            }
            const res = await fetch(@json(route('portal.pay.cashapp', $invoice)), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ token: tokenResult.token }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) return showError(data.message);
            window.location = data.redirect;
        });

        await cashAppPay.attach('#cash-app-pay', { shape: 'semiround', width: 'full' });
    } catch (e) {
        showError('Cash App Pay is unavailable right now. Please choose another way to pay.');
    }
})();
</script>
