# Account statements

An account statement lists a client's invoices, payments and refunds between two dates in date order, with what they owed after each line, and the changes to their credit balance. Staff see it as the **Client statement** report; clients see the same statement in the portal and download it as a PDF.

## In the portal

Clients, and contacts with the **billing** permission, open **Invoices > Account statement** (`/portal/statement`). It shows this year so far; they pick other dates with **From** and **To**, or the **This year**, **Last year** and **Last 12 months** shortcuts. The page has:

- **Opening balance**: what they owed before the first day, from every invoice issued and every payment and refund made before it.
- **Charged** and **Paid** in the dates, and the **closing balance** on the last day.
- A line for each invoice (with its due date), payment (with how it was paid and the gateway's reference), refund, and payment from their credit balance (shown as **Credit applied**), each linked to its invoice, with the balance after it. Below zero, they had paid ahead.
- Their **credit balance** on the first and last day, with each change to it in between: credit added by staff, overpayments, refunds to credit, credit from downgrades and credit used on invoices.

**Download PDF** gives the same statement as a PDF in Harbor colours, named `statement-<from>-to-<to>.pdf`, in the client's language.

Draft and cancelled invoices aren't included, and nor are payments a gateway hasn't finished. Credit isn't counted in the balance until it is applied to an invoice, so credit added by staff shows in the credit balance, not as a payment. Credit notes will show here once they exist.

## For staff

**Reports > Invoices > Client statement** shows the same statement for any client (by number or email address), with a second **Credit balance** table, and exports it to CSV. Both read `App\Billing\AccountStatement`, so the report and the portal always agree.
