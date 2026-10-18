# Tax and EU VAT

Tax on invoices and quotes comes from your tax rules under **Billing > Tax rules**. Each new invoice copies the rules that match the client when it's made, so changing a rule never changes invoices already created. Taxable products are taxed; tax-exempt clients never are.

## Tax rules and levels

A rule has a name, a rate, an optional country and an optional state. The most specific rule that matches the client's address wins: country and state first, then country, then a rule with neither.

Each rule is **level 1** or **level 2**, like WHMCS's Tax Level 1 and Level 2. A client is taxed by one rule of each level, picked the same way, and invoices show one tax line for each. Use level 2 for a second tax on top of the first, such as provincial sales tax with federal GST in Canada. If you only use level 1 rules, nothing changes: invoices have one tax line, as before.

Level 2 tax is charged on the amount alone. Turn on **Compound tax** under **Settings** to charge it on the amount plus level 1 tax instead. With 5% GST and 9.5% compound QST, 100.00 has 5.00 GST and 9.98 QST (9.5% of 105.00). The setting is copied onto each invoice when it's made.

With **Prices include tax** on, the line amounts already include both levels, and the invoice shows how much of the total is each tax.

The **Tax collected** report under **Reports** lists sales and tax by country and rate. Once an invoice in the period has level 2 tax, it adds the level 2 tax and rate, and splits the tax into level 1 and level 2.

## Client tax IDs

Clients have a **Tax ID / VAT number** on their profile. Staff set it on the client form, and clients set it in the portal under **Account** or when they sign up at checkout. It's also in the REST API (`tax_id`) and the client's data export. It's printed under the client's address on their invoices, quotes and PDFs, next to your own tax ID (set under **Appearance**). Each invoice and quote keeps the number the client had when it was made.

## Checking EU VAT numbers

Turn on **Check EU VAT numbers with VIES** under **Settings** to check VAT numbers with the European Commission's [VIES](https://ec.europa.eu/taxation_customs/vies/) service whenever a client or staff member saves one.

- An EU VAT number starts with its country code, such as `DE123456789` (Greece is `EL`, Northern Ireland `XI`). Spaces, dots and dashes are taken out.
- Numbers VIES says don't exist are refused, with an error on the form.
- When VIES or the country's database is down, the number is saved anyway and marked **not verified** on the client's page. Checkout never fails because VIES is down. Save the client again later to check it.
- A number that is already verified isn't checked again unless it changes.
- Any other tax ID, such as a US EIN, is saved as typed and never checked.

## Reverse charge

Turn on **Reverse charge EU VAT** under **Settings** for EU VAT's reverse charge on business-to-business sales. It also turns on VIES checks.

A client is reverse charged when they have an EU VAT number that VIES has verified, from another EU country than your own VAT number. Your country comes from the prefix of your company tax ID under **Appearance**, so that must be your EU VAT number, such as `IE1234567T`. Their invoices then have no tax, and say **Reverse charge** with "VAT to be accounted for by the customer (Article 196, Council Directive 2006/112/EC)" and both VAT numbers, on the portal page and the PDF. Clients in your own country, and clients whose number isn't verified yet, are charged VAT as usual.

Like the tax rate, reverse charge is decided when the invoice is made. Reverse-charge sales show as **Reverse charge** in the Tax collected report.
