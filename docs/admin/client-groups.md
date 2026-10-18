# Client groups and credit limits

A client group, such as resellers or VIPs, is a coloured label on the client's page and in the clients list, and a filter in the clients search. Like WHMCS's client groups, a group can also give its clients a discount and billing settings of its own, and a credit limit. Add and edit groups under **Settings > Client groups**, and put a client in one on their edit page. Each group's row in the list sums up what it changes.

## Discount

**Percent off services** takes that percent off the services clients in the group order, such as `10` or `12.5`. It's its own line on the invoice, "Account discount (10%): Web hosting - example.com", so the client sees it; the group's name is for staff and isn't shown. In the cart and at checkout, a logged-in client sees it as **Your 10% account discount** above the total.

- It comes off **new orders**: from the store, and when staff add a service for the client.
- Tick **Take it off renewals too** to take it off every renewal invoice as well. It's worked out from the client's group when each renewal invoice is made, so moving a client to another group, or changing the percent, changes their next renewal.
- Setup fees, domains, upgrades and downgrades, usage overages and late fees aren't discounted. Neither are invoices staff write by hand.
- Prices shown in the portal (such as on the client's services) are before the group discount; the invoice shows it.

**With a promotion code**, both apply. The promotion comes off first, and the group's percent then comes off what's left. A $10.00 service with a 50% code and a 10% group discount costs $10.00 - $5.00 - $0.50 = $4.50. Because the group's discount is a percent of what's left, the two together can never take a line below zero.

## Settings for the group

These override the global settings under **Automation settings** for the group's clients:

| Setting | What it does |
| --- | --- |
| **No late fees** | Invoices of the group's clients are never charged a late fee. |
| **Never suspend or terminate for overdue invoices** | The nightly billing run doesn't suspend or terminate the group's services however overdue they are. Staff can still do it by hand. As in WHMCS, this covers termination too. |
| **A separate renewal invoice for each service** | The renewal run makes one invoice per service, instead of one invoice for everything due. New orders still come on one invoice. |
| **Credit limit** | The default credit limit for the group's clients, in the default currency. See below. |

## Credit limits

A credit limit lets a client run up unpaid invoices, up to a set amount, before overdue suspension kicks in. When the nightly run would suspend or terminate a client's services for an overdue invoice, it first adds up what's left to pay on all their unpaid invoices (late fees included). If that comes to no more than their limit, it leaves them alone; once they owe more, the usual suspension and termination days apply, counted from each invoice's due date as before. Reminders and late fees aren't affected.

A client's limit comes from, in order:

1. **Their own limit**, on their edit page, in their currency. `0` means no credit, even if their group or the default gives some.
2. **Their group's limit**, if their own is blank.
3. **The default credit limit** under **Automation settings** (or `BILLING_CREDIT_LIMIT` in `.env`), if their group has none. Blank means no default, so clients without a limit of their own or their group's are suspended as usual.

Group and default limits are in the default currency and converted to the client's currency at today's exchange rate.

The **Account** box on the client's page shows their limit, how much of it their unpaid invoices use, with a bar, and where the limit comes from. Over the limit, it says so in red.

## Importing from WHMCS

The WHMCS import brings over each group's discount percent, **Susp/Term Exempt** (as **Never suspend or terminate**) and **Separate Invoices**. WHMCS took the group's discount off each service's recurring amount when it was ordered, and those amounts come over as they are, so imported groups don't discount renewals; tick **Take it off renewals too** only if new services' renewals should get it as well, knowing imported services would then get it twice. WHMCS has no credit limits of its own.
