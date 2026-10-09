<?php

namespace App\Http\Controllers;

use App\Payments\Gateways\PayPal;
use App\Payments\PaymentFailed;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function paypal(Request $request, PayPal $paypal): Response
    {
        $event = $request->json()->all();
        $headers = collect($request->headers->all())->map(fn ($values) => $values[0] ?? null)->all();

        if (! $paypal->isEnabled() || ! $paypal->verifyWebhook($headers, $event)) {
            Log::warning('Rejected PayPal webhook', ['id' => $event['id'] ?? null]);

            return response('', 400);
        }

        if (($event['event_type'] ?? null) === 'PAYMENT.CAPTURE.COMPLETED') {
            try {
                $paypal->captureCompleted($event['resource'] ?? []);
            } catch (PaymentFailed) {
                // Already logged; acknowledge so PayPal stops retrying.
            }
        }

        return response('', 200);
    }
}
