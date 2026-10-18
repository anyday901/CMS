# Billable items

A billable item is a charge you add to a client by hand, such as an hour of support, a design job or a monthly maintenance fee, and let the billing run invoice for you. It works like WHMCS's billable items: each one has an invoice action that says when it goes on an invoice, and once it's on one it isn't billed again.

## Adding an item

Open the client, go to **Billing > Billable items** and press **Add billable item**. Fill in:

- **Description**: the invoice line, such as "Website changes". The client sees it.
- **Amount**, or **Hours** and an **hourly rate**. With hours, the amount is the hours times the rate, rounded to the cent, and the invoice line says so, like "Consulting (1.50 hours at $75.00/hr)". Amounts are in the client's currency.
- **Invoice action**: see below.
- **Due date**: when it's due. Needed to invoice on the due date or to recur.
- **Recur every** and **Times**: for recurring items, how often and how many times. Leave Times empty to recur until you stop it.
- **Taxable**: whether the client's tax applies to it, as on any invoice line.

## Invoice actions

| Action | What the billing run does |
| --- | --- |
| Don't invoice | Nothing. The item waits on the client until you pick another action. |
| Invoice on the next billing run | Invoices it the next night, due on its due date (or today, if that has passed). |
| Add to the next renewal invoice | Adds it to the next invoice the billing run makes for the client's services renewing. A client with nothing renewing doesn't get one, so the item waits. |
| Invoice on the due date | Invoices it once its due date is within the days renewals are invoiced ahead (**Invoice this many days before the due date** under Automation settings), due on that date. |
| Recur | Invoices it on its due date in the same way, then every so many days, weeks, months or years after it, for the number of times set or until stopped. Each line has the date it's for. A month-end date stays at the month's end, so Jan 31 monthly is next due on Feb 28. |

Each night's billing run (`php artisan billing:run`) puts all of a client's items that are due on one invoice. When a client's services are being invoiced the same night, the items go on that renewal invoice instead, along with any set for the next renewal invoice. The usual new invoice email goes out, and account credit is applied if that setting is on.

Each invoice line remembers its item, and each item the last invoice it went on, so running the billing run again (or twice in a night) bills nothing twice. In reports, billable item lines show as **Billable items** under income by product.

## Status and changes

The client's **Billable items** section lists every item with its amount, its action (with the next due date and how many times a recurring item has been invoiced) and the invoice it last went on.

Staff with the billing permission can edit or delete an item until it's invoiced: a one-off item until it's on an invoice, a recurring one until it has been invoiced as many times as set. Changes to a recurring item apply from its next invoice. Staff without the permission can see the items but not change them.

If the invoice a one-off item went on is cancelled, the item isn't billed again by itself: it shows the cancelled invoice and can be edited again, and saving it puts it back in line by its invoice action (pick **Don't invoice** to keep it off). A cancelled invoice for a recurring item still counts as one of its times. Removing an item's line from an invoice by editing the invoice doesn't put the item back in line either.

Adding, editing and deleting items is written to the client's activity log. Merging two clients moves the items to the client they're merged into.

## Project time

Logged project time isn't turned into billable items. The **Project management** add-on already puts a project's unbilled time on a draft invoice (**Invoice unbilled time** on the project), one line per entry, and keeps track of what has been billed itself. Use a billable item with hours and a rate for time you don't track in a project.
