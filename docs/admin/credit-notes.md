# Credit notes

A credit note gives a client money back against an invoice they've paid, for some or all of its lines, with a reason. It has its own number (`CN-1`, `CN-2` and so on) and a PDF, and the money goes back either to the client's account credit or as a refund of one of their payments, through the existing refund flow.

Use a credit note rather than a plain refund when you want a document for the books that says what was credited and why, such as a refund for an outage or a service cancelled part way through a period. A plain **Refund** under a payment still works as before.

## Issuing a credit note

Open the invoice and press **Issue credit note**. The button shows on paid invoices, and on unpaid ones with a part payment, for staff with the billing permission (**Create and edit invoices and quotes**, part of the Admin and Billing roles).

1. Enter how much of each line to credit. Leave a line blank to credit none of it. Each line shows what's left to credit after earlier credit notes; discount lines can't be credited.
2. Write the **reason**. The client sees it in the portal and on the PDF.
3. Choose how to **give the money back**:
    - **Add to the client's account credit**: the money goes to their credit balance, ready for their next invoice, and shows in their credit history. It's taken from the invoice's payments, newest first, as refunds to credit.
    - **Send back through PayPal** (or the payment's other gateway): a refund of that payment through the gateway, as the **Refund** button would send. It's offered for payments made through a gateway that can refund.
    - **Already refunded outside the app**: records a refund of that payment that you've sent yourself, such as by cheque or bank transfer.

    A refund of one payment needs that payment to have enough left to refund. Choose account credit, or issue two credit notes, when the money came in as several payments.
4. Press **Issue credit note**.

Tax is worked out at the invoice's rate, the same way as on the invoice: crediting $10.00 of a line taxed at 10% gives back $11.00, and on an invoice whose prices include tax the tax is the part of the amount that's tax. A credit note can't give back more than was paid on the invoice, less earlier credit notes, nor more of a line than is left of it.

If the gateway turns the refund down, nothing is recorded and the credit note number isn't used, so credit notes are numbered with no gaps. A refund the gateway reports as pending is confirmed by the nightly billing run, as with any refund.

## What it changes on the invoice

The invoice page lists its credit notes, shows a **Credited** line in its totals, and marks the refunds a credit note made. What the client owes doesn't change: the credit note takes its amount off the invoice's total, and its refund takes the same amount off what's been paid. So:

- A paid invoice credited in part stays paid. Credited in full, it's marked refunded.
- A part paid invoice still has the same balance due, and stays unpaid.

An invoice with a credit note against it can no longer be edited, so its lines stay as the credit note describes them. Services on the invoice are left as they are; suspend or cancel them yourself if needed.

## Where credit notes show

- **Admin**: on the invoice page, and in a **Credit notes** table under **Billing > Invoices** on the client's page. The number links to the PDF. Every staff member can download the PDF, as with invoices.
- **Portal**: clients and contacts with the billing permission see them on the invoice's page and in a **Credit notes** list under **Invoices**, and can download the PDF. The portal text is translated into German, French and Spanish.
- **PDF**: your company details, the client's billing address, the invoice it's against, the lines credited, tax, total, reason and how the money was paid back.

Each credit note is written to the activity log ("Issued credit note CN-1 for $27.00 on invoice #42: Outage on Oct 3"), along with the refunds it made. Merging two clients moves their credit notes, and a client's data export includes them. The invoice aging report counts what's owed after credit notes.

## Numbering

Credit notes are numbered from their own sequence, in the order they're issued. Change the format (`CN-{NUMBER}` by default, with the same `{YEAR}`, `{MONTH}`, `{DAY}` and `{NUMBER}` placeholders as invoices) and the next number under **Settings > Invoice numbering**, or set `BILLING_CREDIT_NOTE_NUMBER_FORMAT` in `.env`. See [Invoice numbering](invoice-numbering.md).

## Not included yet

Credit notes don't send an email, can't be edited or deleted once issued, and aren't yet in the REST API, webhooks or the tax report.
