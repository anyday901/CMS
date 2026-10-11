<?php

namespace App\Http\Controllers;

use App\Payments\Gateways\PayPal;
use App\Payments\Gateways\SquareWebhook;
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

        $type = (string) ($event['event_type'] ?? '');

        if ($type === 'PAYMENT.CAPTURE.COMPLETED') {
            try {
                $paypal->captureCompleted($event['resource'] ?? []);
            } catch (PaymentFailed) {
                // Already logged; acknowledge so PayPal stops retrying.
            }
        } elseif (str_starts_with($type, 'CUSTOMER.DISPUTE.')) {
            $paypal->disputeUpdated($event['resource'] ?? []);
        }

        return response('', 200);
    }

    public function square(Request $request, SquareWebhook $square): Response
    {
        if (! $square->verify($request->getContent(), $request->header('x-square-hmacsha256-signature'))) {
            Log::warning('Rejected Square webhook', ['id' => $request->json('event_id')]);

            return response('', 400);
        }

        $square->handle($request->json()->all());

        return response('', 200);
    }
}
