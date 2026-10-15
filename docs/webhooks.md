# Outgoing webhooks and notifications

When something happens, such as an invoice being paid or a ticket opened, the app fires an **event**. Events go to:

- **Webhooks** (Settings > Webhooks): a signed JSON POST to a URL of yours.
- **Chat notifications** (Settings > Chat notifications): a line in a Slack, Discord or Microsoft Teams channel.
- **SMS** (Settings > SMS): a text to staff numbers, and to clients who agreed to account texts.
- **Plugins and add-ons**, which listen in code (see [addons.md](addons.md)).

All of them are sent from the queue, so run a queue worker in production (`php artisan queue:work`).

## Events

| Event | When |
|---|---|
| `client.created` | A client signs up at checkout, or staff or the API add one |
| `order.placed` | A client checks out in the store |
| `invoice.created` | An invoice is issued |
| `invoice.paid` | An invoice is paid in full |
| `service.activated` | A service is activated |
| `service.changed` | A service is upgraded or downgraded |
| `service.suspended`, `service.unsuspended`, `service.terminated` | A service's status changes |
| `ticket.opened` | A client or guest opens a ticket |
| `ticket.replied` | A client or guest replies to a ticket |
| `ticket.answered` | Staff reply to a ticket (not internal notes) |
| `staff.alert` | Something needs staff: a failed provisioning action, a payment dispute, a manual task, a server down |
| `client.merged` | Staff merge one client account into another (`from_client_id`, `into_client_id`) |
| `webhook.test` | **Send a test** on a webhook's page |

Add-ons add their own, such as `project.created`; the webhook and chat forms list every event available.

## Webhook requests

Each delivery is a `POST` with a JSON body:

```json
{
  "id": "9b1c6f9e-6a35-4b8e-9d7f-3c2e1b0a9f41",
  "event": "invoice.paid",
  "occurred_at": "2026-10-15T21:04:19+00:00",
  "summary": "Invoice #1042 paid by Jane Doe: $15.00",
  "url": "https://billing.example.com/admin/invoices/1042",
  "data": {
    "id": 1042, "number": "1042", "client_id": 7, "status": "paid", "currency": "USD",
    "subtotal": 1500, "tax": 0, "total": 1500, "balance": 0,
    "issue_date": "2026-10-15", "due_date": "2026-10-15", "paid_at": "2026-10-15T21:04:19+00:00"
  }
}
```

Money is in minor units (cents) with its currency. `data` holds the client, order, invoice, service or ticket the event is about, with the same fields the REST API returns.

Headers:

- `X-Webhook-Event`: the event key.
- `X-Webhook-Delivery`: the event's `id`. Retries keep it, so skip ids you've already handled.
- `X-Webhook-Signature`: `sha256=` followed by the HMAC-SHA256 of the raw body, keyed with the webhook's signing secret (shown on its page).

Check the signature before trusting a delivery, for example in PHP:

```php
$expected = 'sha256='.hash_hmac('sha256', file_get_contents('php://input'), $secret);
if (! hash_equals($expected, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
```

Answer with any 2xx status within 15 seconds. Anything else is tried again after 1 minute, 5 minutes, 30 minutes and 2 hours. Each webhook's page lists its last 50 deliveries with what was sent and the answer, and can send one again. Deliveries are kept 30 days.

## Chat notifications

Each channel posts the event's summary with a link to it in the admin area.

- **Slack**: create a Slack app with an incoming webhook for the channel, and paste its `https://hooks.slack.com/services/...` URL.
- **Discord**: Server settings > Integrations > Webhooks > New webhook, then copy its URL. Mentions such as `@everyone` in names are switched off.
- **Microsoft Teams**: in the channel, add the Workflows app's "Post to a channel when a webhook request is received" template and paste the URL it gives. Messages are sent as Adaptive Cards.

The URL is kept encrypted and isn't shown again. **Send a test** checks it straight away.

## SMS

Pick a provider under Settings > SMS (Twilio, Vonage, or Log only for trying it out), enter its credentials (secrets are kept encrypted) and the number or sender ID to send from.

- **Staff texts**: list staff numbers and tick the events they're texted about.
- **Client texts**: tick which of new invoice, payment received, service activated, service suspended and support replied clients are texted about. Only clients who agreed (under Account in the portal, or ticked by staff on their edit page) and have a phone number get them. Numbers without a country code get the default one from the settings.

The page lists recent texts with any error. Texts are kept 90 days.

Another provider is a class implementing `App\Integrations\Sms\SmsProvider` (a key, a name, its credential fields and `send()`), listed in `config/sms.php`.
