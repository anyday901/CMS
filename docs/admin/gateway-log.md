# Gateway log

The gateway log keeps every call a payment gateway makes to CMS, so you can see what PayPal, Stripe or Square sent and what came of it, like WHMCS's gateway log. Open it from **Settings > Payment gateways > Gateway log** (`/admin/settings/gateway-log`). Staff need the **Change settings** permission, as for the activity and email logs.

## What is logged

| Gateway | Calls |
| --- | --- |
| PayPal | The webhook (`/webhooks/paypal`) and the capture after the client approves a payment. |
| Cash App (Square) | The Square webhook (`/webhooks/square`) and the Cash App Pay charge. |
| Stripe | The webhook (`/webhooks/stripe`), the card payment confirmation, the return from a payment that needed a redirect (such as to the client's bank), and the return and confirmation when a client saves a card. |

Each entry has the time, gateway, the path called, the event type the gateway gave (such as `payment_intent.succeeded`), the client and invoice when known, the caller's IP address, the answer CMS gave, and the outcome:

- **Accepted**: CMS took the call.
- **Rejected**: CMS turned it away, such as a webhook with a bad signature, a gateway that's turned off, a payment the gateway declined, or an invoice with nothing left to pay. The reason is shown.
- **Error**: something failed on this side while handling it. The error is shown, and is in the Laravel log too.

Filter by gateway and outcome, or search the event, message and payload. Click a date to see the whole payload.

## Secrets and card data

Secrets and card details are replaced with `[redacted]` before anything is kept: any value whose key contains `secret`, `password`, `token`, `signature`, `nonce`, `cvc`, `cvv`, `iban` or similar, card numbers, expiry dates and BINs inside card objects, and any card number (13 to 19 digits passing the Luhn check) in free text. The card brand and last four digits stay, as they are on the client's page. Signature headers aren't kept. Payloads over 64 KB are cut short.

## How long entries are kept

Entries are deleted with the activity log: set **Keep entries for this many days** under **Settings > Activity log**, and the nightly `model:prune` task deletes older gateway log entries too. Left blank, they are kept forever. Anonymising a client deletes the entries for them and their invoices, and merging clients moves them.

## For developers

Logging lives in one place: the `App\Http\Middleware\LogGatewayCallback` middleware, which hands the request and the answer to `App\Payments\GatewayLogger`. Gateways don't write to the log themselves. A plugin gateway's routes are logged by adding the middleware with its key:

```php
Route::post('webhooks/mygateway', MyGatewayWebhook::class)->middleware(LogGatewayCallback::using('mygateway'));
```
