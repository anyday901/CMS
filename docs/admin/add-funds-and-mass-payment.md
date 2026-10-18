# Add funds, mass payment and merging invoices

Three ways to move money around invoices, as WHMCS has them: clients can prepay into their credit balance (**add funds**), pay several unpaid invoices at once (**mass payment**), and staff can combine a client's unpaid invoices into one (**merge**).

Both client features are under **Settings**, in **Add funds and mass payment**. Add funds is off until you turn it on; mass payment is on.

## Add funds

With **Let clients add funds** ticked, clients and contacts with the billing permission see **Add funds** on the portal's **Invoices** page. They enter an amount and are taken to an add funds invoice for it, due today, which they pay with any enabled gateway (Stripe, PayPal, Cash App Pay, or bank transfer and mail-in, which you record by hand). Once it's paid, the amount is added to their credit balance, with a line in the credit history, and from there it pays their invoices like any other credit: automatically on new invoices if **paying new invoices from credit** is on under **Automation settings**, or when they choose **Use my account credit** on an invoice.

Add funds invoices are never taxed, since the tax is charged on the invoices the credit later pays. They aren't paid from credit, charged to a saved card by the billing run, sent overdue reminders or given late fees, and they don't count towards what the client owes on the dashboards. A client who doesn't pay one can just leave it; you can cancel it.

The limits are amounts in your default currency, converted at today's rate for clients billed in another:

| Setting | Default | Meaning |
| --- | --- | --- |
| Least per payment | 10.00 | The smallest amount a client can add. |
| Most per payment | 500.00 | The largest amount on one add funds invoice. Blank for no limit. |
| Most credit balance | 1000.00 | The client's credit, plus their unpaid add funds invoices, can't go over this. Blank for no limit. |

The same settings can be set in `.env` with `BILLING_ADD_FUNDS`, `BILLING_ADD_FUNDS_MINIMUM`, `BILLING_ADD_FUNDS_MAXIMUM` and `BILLING_ADD_FUNDS_MAXIMUM_BALANCE`; values saved on the settings page win.

## Mass payment

When a client has two or more unpaid invoices in their currency, the portal's **Invoices** page has a checkbox by each. They tick the ones to pay and choose **Pay selected invoices**, and get one mass payment invoice, due today, with a line for what is left to pay on each (links to the originals in the admin area). Paying it, with any gateway, marks each of those invoices paid, so their services renew or unsuspend just as if they were paid one by one.

Behind the scenes the payment goes into the client's credit and is then applied to each invoice, so the gateway payment stays on the mass payment invoice (refund it there) and each original shows an account credit payment. Income is counted once. If one of the invoices was paid or cancelled some other way before the mass payment was paid, its share stays in the client's credit.

Until it's paid, the original invoices stay payable on their own, and each one's portal page points to the mass payment that covers it. Making a new mass payment for any of the same invoices cancels the old one. The nightly billing run cancels an unpaid mass payment with nothing paid on it once one of its invoices has been paid or cancelled on its own, or 14 days after it was due. Cancelling one, by hand or this way, leaves the original invoices as they were.

Mass payment invoices aren't charged to saved cards, reminded, given late fees or counted as owed (their invoices already are). Untick **Let clients tick several unpaid invoices** to hide the checkboxes, or set `BILLING_MASS_PAY=false`.

## Merging unpaid invoices

Staff with the billing permission see checkboxes by unpaid invoices under **Billing > Invoices** on a client's page and on the **Invoices** list. Tick two or more of one client's and choose **Merge selected**.

- The invoice kept is the oldest one with a payment on it, or the oldest if none has. It gets every line of the others, the earliest due date of them all, and its tax worked out again on the combined lines.
- The others are cancelled, keeping their own lines for the record, with "Merged into invoice #…" added to their notes (shown on the admin invoice page and their PDF).
- Anything already paid on the others, including credit, is refunded to the client's credit against the payment it came from, and then applied to the kept invoice. Nothing paid is lost, and gateway payments stay on the invoices they were made on.
- Orders, pending upgrades and usage billing that pointed at a merged invoice point at the kept one, so paying it activates the services and carries out the upgrades. Any unpaid mass payment covering them is cancelled.

Only unpaid invoices of the same client, in the same currency and with the same tax rate can be merged, and add funds and mass payment invoices can't be. Merging doesn't email the client. Each merge is written to the activity log.
