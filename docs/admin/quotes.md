# Quotes

A quote is a priced proposal for a client: a subject, line items, an optional proposal text and a date it's valid until. Send it, and the client can read it in the portal, download the PDF, and accept or decline it. Accepting turns it into an unpaid invoice with the same lines and total, and takes the client straight to paying it.

## Making a quote

Open the client, go to **Billing > Quotes** and press **New quote**. Fill in:

- **Subject**, such as "Website redesign". It's shown to the client and used in the email subject.
- **Quote date** and **Valid until** (30 days from today by default). After that date the client can no longer accept it.
- **Stage**: see below. New quotes start as **Draft**.
- **Line items**: a description, an amount and whether the line is taxable, just like the invoice editor. Use a negative amount for a discount. The total must be more than zero.
- **Proposal**: text shown to the client on the quote page and the PDF, such as what's included and timescales.
- **Admin notes**: for staff only. Never shown to the client.

Tax is worked out the same way as on invoices: from the client's address and your tax rules, with **Prices include tax** under **Settings** deciding whether line amounts already include it. The tax rate is fixed when the quote is made, and the invoice it becomes keeps that rate, so the client pays what they were quoted even if your tax rules change in between. Quotes are in the client's currency.

Each quote's page has **Edit**, **Duplicate** (a new draft with the same lines and wording, valid for as long as the original), **Delete**, **Download PDF**, **Send to client** and **Convert to invoice**. Once a quote has become an invoice it can no longer be edited; change the invoice instead. Deleting a converted quote keeps its invoice.

## Stages

| Stage | Meaning | Client sees it |
| --- | --- | --- |
| Draft | Still being written. | No |
| Delivered | Sent to the client, waiting for their answer. | Yes, and can accept or decline |
| On hold | Waiting on something, such as the client's go-ahead. | Yes, and can accept or decline |
| Accepted | Accepted by the client, or converted by staff. | Yes, with a link to its invoice |
| Lost | The client declined it. | No |
| Dead | No longer being pursued. | No |

You can set the stage by hand when editing a quote. Setting it to Delivered or On hold shows it in the client's portal without emailing them.

## Sending a quote

**Send to client** emails the quote to the client with the PDF attached, using the **Quote delivered** email template, and marks it Delivered. Contacts who get invoice emails are copied in. Edit the wording under **Email templates**; it's translated into German, French and Spanish for clients who use those languages. If the template is turned off, or the client has no email address, the quote is still marked Delivered and shows in the portal.

## Accepting and converting

In the portal, clients and contacts with the **billing** permission see a **Quotes** page (once they have a quote to see) listing their delivered, on hold and accepted quotes. Drafts never show. On a quote they can:

- **Accept quote**: creates an unpaid invoice with the same lines and tax, due in 7 days, marks the quote Accepted, and takes them to the invoice to pay it. The usual new invoice email goes out, and account credit is applied if that setting is on. If a terms of service link is set for the store, they must tick that they agree to it first.
- **Decline**: marks the quote Lost and removes it from their portal.

A quote past its valid until date shows as Expired and can no longer be accepted or declined. Change the date, or duplicate it, to offer it again.

Staff can do the same from the admin area with **Convert to invoice**, for example when the client accepts by phone. Each quote's page links to its invoice once converted.

## Permissions and history

Every staff member can see quotes, as with invoices. Making, editing, sending, converting and deleting them needs the billing permission (**Create and edit invoices and quotes** on the staff form, part of the Admin and Billing roles).

Each change is written to the activity log and listed in the quote's **History**: created, edited, sent, copied, accepted or declined by the client, converted and deleted. The admin search finds quotes by number or subject. Merging two clients moves their quotes too, and a client's data export includes the quotes sent to them.
