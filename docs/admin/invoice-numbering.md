# Invoice numbering

By default an invoice's number is its id, given when it's created. Unpaid invoices that are later cancelled keep their numbers, so the paid invoices in your books have gaps between them. Many countries' tax rules want paid invoices numbered in order with no gaps. **Sequential paid numbers** does that, like WHMCS's "Sequential Paid Invoice Numbering".

## Turning it on

Go to **Settings > Invoice numbering** and tick **Number invoices only once they're paid**. It's off on new installs and after upgrading, so nothing changes until you turn it on.

- **Number format**: how paid numbers are written. `{NUMBER}` is the next number in the sequence; `{YEAR}`, `{MONTH}` and `{DAY}` are the date of payment. The default is `INV-{YEAR}-{NUMBER}`, which gives `INV-2026-1`, `INV-2026-2` and so on. The format needs a letter or symbol besides the placeholders (`{YEAR}-{NUMBER}` is fine, `{NUMBER}` alone isn't), so a paid number can never be the same as a proforma's id.
- **Next number**: the number the next paid invoice takes. Set it to carry on from your old system, for example `1001` if your last paid invoice in WHMCS was 1000. The page shows what the next paid invoice will be called.

The numbers don't start again each year by themselves. If you want them to, set **Next number** back to 1 on January 1 with `{YEAR}` in the format.

The same settings can come from `.env`, as `BILLING_SEQUENTIAL_PAID_NUMBERS=true` and `BILLING_INVOICE_NUMBER_FORMAT`; what you save on the page wins.

## Proforma invoices

With the setting on, every new invoice (renewals, store orders, invoices staff create, quotes that become invoices) starts as a **proforma**. It's shown as "Proforma #" and its id, such as **Proforma #123**, on the admin invoice page and lists, in the client's portal, on the PDF (saved as `proforma-123.pdf`) and in emails, where `{invoice_number}` becomes "123 (proforma)". Clients pay a proforma like any invoice, and bank transfer and mail-in instructions ask them to use its number as the reference.

The moment a proforma is paid in full it takes the next number, such as **INV-2026-42**, and is shown as an invoice from then on: the receipt email, the PDF and the portal all use the new number. A part payment leaves it a proforma until the rest is paid. The change is written to the activity log ("Numbered proforma #123 as invoice #INV-2026-42 on payment").

A proforma that's cancelled never takes a number, so cancelled invoices leave no gaps. The admin search still finds every invoice by its proforma number (its id) as well as its paid number.

## How gaps are avoided

The next number is kept in one database row, which is locked while a payment takes a number. Two payments at the same moment, such as a gateway webhook and a staff member recording a payment, queue up and get one number each. If a payment fails after taking a number, the number is given back with the rest of the payment. A number that's already in use, for example by an invoice you imported, is passed over.

Saving the settings page only changes the sequence when you change **Next number**, so saving it while payments come in doesn't wind the numbers back.

## Turning it off again

Invoices created before the setting was turned on keep their numbers when they're paid. Turning it off stops new invoices being proformas; proformas made while it was on still take a sequential number when they're paid, so none of them are left without one.
