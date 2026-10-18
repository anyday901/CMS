# Stripe and automatic charging

Stripe adds card payments to unpaid invoices in the client area, lets clients save cards, and lets the nightly billing run charge saved cards when invoices fall due. Card details go from the client's browser straight to Stripe (through Stripe.js and the Payment Element); CMS keeps only Stripe's ids and each card's brand, last four digits and expiry.

## Setting up Stripe

1. In the [Stripe dashboard](https://dashboard.stripe.com/apikeys), under **Developers > API keys**, copy the publishable key (`pk_...`) and the secret key (`sk_...`). Use the test keys first; they take only [Stripe's test cards](https://docs.stripe.com/testing).
2. Under **Developers > Webhooks**, add an endpoint for `https://your-domain/webhooks/stripe` with these events:
    - `payment_intent.succeeded` and `payment_intent.payment_failed`
    - `charge.refunded` and `charge.refund.updated`
    - `charge.dispute.created`, `charge.dispute.updated` and `charge.dispute.closed`
    - `setup_intent.succeeded` and `payment_method.detached`

    Copy the endpoint's signing secret (`whsec_...`).
3. In CMS, open **Settings > Payment gateways** and enter the three values. Secrets are stored encrypted; leave a secret field blank to keep the saved one. The same page shows the webhook URL to use.

The keys can instead go in `.env`; values saved in the admin area win:

```env
STRIPE_PUBLISHABLE_KEY=pk_live_...
STRIPE_SECRET_KEY=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Stripe shows on an invoice as soon as both keys are set. Requests are made with Stripe API version `2024-06-20` (`STRIPE_API_VERSION`), whatever your account's default is.

## What clients see

- **Paying an invoice**: a card form for the balance, in the invoice's currency, with **Save this card for future payments**. A saved card can pay the next invoice in one click. When the bank asks for 3-D Secure, Stripe shows that step.
- **Payment methods** (from the Invoices page): saved cards, the default card, **Make default**, **Remove** (removes it at Stripe too), **Add a card**, and **Automatic payments** on or off.
- A contact needs the **billing** permission to pay invoices or manage cards.

The client's Stripe customer is created the first time they save a card, and kept on the client.

## Automatic charging

Each night the billing run (see **Settings > Automation**) charges unpaid invoices to the client's default saved card:

| Setting | Default | What it does |
|---|---|---|
| **Charge this many days before the due date** | 0 | 0 charges on the due date. `BILLING_AUTO_CHARGE_DAYS_BEFORE_DUE` in `.env`. |
| **Try a failed charge again this many days after the due date** | 1, 3, 5 | One more try for each number. Blank means no retries. `BILLING_CHARGE_RETRY_DAYS`. |

- An invoice is never charged automatically twice on one day, and a night the run missed is caught up with one charge, not several.
- A charge Stripe is still processing isn't tried again; the webhook records it when it clears.
- Clients who turned **Automatic payments** off in the client area are not charged. Staff can turn it on or off on the client's **Payment methods** section (WHMCS calls this "disable automatic CC processing").
- A paid invoice gets the usual **Payment receipt** email. A failed automatic charge sends the **Payment failed** email, with Stripe's reason and links to pay the invoice and to update payment methods. Edit it under **Email templates**.
- Every try, by the billing run or by staff, is listed under **Saved card charges** on the admin invoice page, with the reason for any failure.

## Staff

- The client page has a **Payment methods** section listing saved cards (brand, last four, expiry), with **Remove**.
- An unpaid invoice of a client with saved cards has **Charge saved card**, which charges the balance straight away. Staff charges don't email the client when they fail.
- **Refund** on a Stripe payment can send the money back through Stripe. Refunds made in the Stripe dashboard are recorded from the webhook.
- Disputes show on the payment; a lost dispute is recorded as a refund of the disputed amount. Answer disputes in the Stripe dashboard.

## Currencies

CMS keeps every amount in hundredths. Stripe takes zero-decimal currencies such as JPY and KRW in whole units, and three-decimal ones such as KWD in thousandths, so amounts are converted both ways. A zero-decimal invoice with a fraction left (which CMS doesn't create itself) is rounded up, and the difference goes to the client's credit.
