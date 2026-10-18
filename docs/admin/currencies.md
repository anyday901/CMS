# Currencies

Each client is billed in one currency, and their prices, invoices, payments and credit are all in it. **Settings > Currencies** lists the currencies you sell in, which one is the default, and the exchange rate of each against the default, as in WHMCS.

## The default currency

New clients get the default currency unless they pick another in the store or staff choose one. Exchange rates are stated against it, and the dashboard's income and unpaid totals are shown in it.

When CMS is installed the default is `BILLING_CURRENCY` from `.env` (`USD` if unset). After that, change it with **Make default** on the Currencies page; `BILLING_CURRENCY` no longer has any effect. Making another currency the default:

- restates every rate against the new default (with USD default and EUR at 0.8, making EUR the default puts USD at 1.25);
- moves configurable option prices so their main prices are in the new default. An option with no prices in the new default gets them converted from the old one;
- leaves clients, invoices and product and TLD prices as they are.

Upgrading an install that already had prices, clients or invoices in several currencies adds each of those currencies at a rate of 1. Update the rates before relying on converted totals.

## Adding and removing currencies

Add a currency with its three-letter ISO 4217 code, such as `EUR`, and optionally its rate. Amounts are shown with the usual symbol for each code.

A currency can't be deleted while it is the default, or while any client, invoice or payment uses it. Deleting a currency also deletes its product, configurable option and TLD prices.

## Exchange rates

A rate is how many of the currency make one of the default currency. With USD as the default, a EUR rate of `0.92` means one dollar is 92 euro cents. Rates are kept as exact decimals (up to 10 places), and amounts are converted without floating point and rounded half up to the cent.

Edit a rate by clicking the currency's code, or press **Update exchange rates** to take today's reference rates from the [European Central Bank](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html). The ECB quotes about 30 currencies against the euro; CMS crosses them to your default currency, so with USD as the default, GBP's rate is (GBP per euro) ÷ (USD per euro). A currency the ECB doesn't quote keeps the rate you set, and the page says which ones that was. If the ECB doesn't quote the default currency, no rates change.

To update rates every day, tick **Update exchange rates from the European Central Bank every day** under **Settings > Automation**. The `currencies:update-rates` task runs at 16:30 server time, after the ECB publishes, and shows on the **Cron status** page. From the command line:

```bash
php artisan currencies:update-rates            # update the rates now
php artisan currencies:update-rates --prices   # then convert every price, as below
```

Changing a rate doesn't change any price by itself.

## Prices in each currency

A client only sees products, billing cycles and TLD terms priced in their currency, so every currency needs its own prices:

- **Products**: the product page has a price and setup fee per billing cycle for the default currency, and a grid below it for the other currencies. Leave a price blank to not sell that cycle in that currency.
- **Configurable options**: below the default currency's prices is a table for each other currency. A choice priced in the default currency can't be ordered in a currency where its row is blank; enter 0 to make it free there. A choice that is free in the default currency is free everywhere.
- **TLDs**: the price grid has a table per currency.

**Update product prices** on the Currencies page fills all of these in for you: every product, configurable option and TLD price in each other currency is set to the default currency's price converted at the current rate. Prices that exist only in another currency are left alone, and nothing is deleted. Services already ordered keep the price they were sold at.

## In the store

When there is more than one currency, guests get a **Currency** picker in the store's menu. The store and cart show prices in the chosen currency, and a guest who signs up at checkout is billed in it. Signed-in clients always see their account's currency.

## A client's currency

Staff choose a client's currency on the client's **Edit** page. Since invoices, payments and credit are in that currency, it can't be changed once the client has any of them, as in WHMCS; open a new client account to bill someone in another currency.

## Totals across clients

The dashboard adds up income and unpaid invoices in all currencies by converting each to the default currency at today's rates. Amounts in a currency that isn't on the Currencies page have no rate and are left out. Reports have a currency filter and show one currency at a time.
