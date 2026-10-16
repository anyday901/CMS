# Domains

CMS sells domain registrations and transfers in the store, renews them, and lets clients manage their nameservers, DNS records, contact details, ID protection and transfer lock in the portal. Each domain is registered at a registrar through a registrar module: Enom, Namecheap, OpenSRS and ResellerClub are built in, and [more can be added](../developers/registrars.md).

!!! warning "Not yet tried against live registrar accounts"
    The four built-in modules follow each registrar's published API and are tested against recorded replies, but haven't been run against real reseller accounts yet. Try a registration in the registrar's test environment (each module has a test mode switch) before selling domains.

## Setting up

1. **Settings > Registrars and domain settings**: enter the credentials of the registrars you use and save, then press **Test** for each to check they work. Secrets are stored encrypted and not shown again; leave a secret blank to keep the saved one. Most registrars only accept API calls from addresses you allow in their control panel, so add your server's IP address there too.
2. On the same page, set the **default nameservers** new domains get (at least two) unless the client gives their own, how many days before expiry the **renewal invoice** is made (30 by default), the **reminder days** (30, 7 and 1 by default), and whether new domains **renew automatically**.
3. **Settings > TLDs and pricing**: add each extension you sell, such as `com` or `co.uk`, pick its registrar, and fill in the price grid. Each term (1, 2, 3, 5 or 10 years) has a register and renew price per currency, and the 1 year term a transfer price too, since a transfer adds one year at the registrar; leave a price blank to not sell that term. **ID protection** has a price per term too, where 0 means it's free. Tick **DNS management** to let clients edit DNS records (when the registrar module supports it), and **EPP code required** for extensions whose transfers need one.
4. Make sure the scheduler runs (see [Health](system.md)): `domains:run` makes renewal invoices and sends reminders every night, and `domains:sync` fetches expiry dates and statuses from the registrars.

The **Domains** menu shows once a TLD or domain exists.

## Ordering

The store's **Domains** page searches a name. A name typed without an extension is checked under each TLD sold; a full name is checked along with a few other extensions. Availability comes from the TLD's registrar, or from the public registry records (RDAP, or a port 43 WHOIS server set on the TLD) when no registrar is set up. **WHOIS lookup** shows a registered domain's public record.

Clients add a registration or a transfer (with the domain's EPP code) to the cart, pick the term and ID protection, and check out as usual; a domain can be ordered on its own or with hosting. Products whose module needs a domain offer **Also register this domain with us** on the order form.

Once the invoice is paid, the domain is registered or the transfer is started at the registrar. Transfers usually take about five days; the nightly sync marks the domain active when the registrar has it. With **Hold new orders for review** on, domains wait until the order is accepted, and cancelling or flagging the order cancels them.

If a registration fails (for example, the registrant's address is missing or the registrar refuses it), the domain stays pending, staff with **Manage clients** get an alert, and the domain page shows the error with a button to try again.

## Renewals, reminders and expiry

- **Auto-renew** (on by default) makes a renewal invoice the set number of days before expiry, one invoice per client for all their domains due. Paying it renews the domain at the registrar.
- Clients can also **Renew now** from the domain's page in the portal, for any term the TLD is priced for. Turning ID protection on adds its price to the renewal.
- **Reminders** go out at each of the reminder days before expiry, once per domain and expiry date, saying whether auto-renew is on. Domains whose renewal is already paid get none.
- A domain past its expiry date is marked **Expired** and the client gets one notice. It can still be renewed while the registrar allows it.

The emails are **Domain registered**, **Domain transferred**, **Domain renewed**, **Domain renewal reminder** and **Domain expired** under **Email templates**.

## The nightly sync

`domains:sync` asks each domain's registrar for its expiry date, status, nameservers, lock and ID protection, 200 domains a night, the longest unsynced first. It picks up renewals made elsewhere, finished transfers, expired domains, and domains that left (marked **Transferred away**). A domain's renewal due date follows its expiry date, except while a renewal invoice for the current date is open. **Sync now** on a domain's admin page does the same for one domain.

## Managing domains

In the portal, clients (and contacts with the **services** permission) can change the nameservers, edit DNS records, change the registrant contact, turn ID protection and the transfer lock on or off, and get the EPP code (some registrars email it to the registrant instead). Each change goes to the registrar straight away and is shown if it's refused. Contacts with the **orders** permission can renew and change auto-renew.

Staff see every domain under **Domains**, filtered by status or by those expiring in the next 30 days, and a client's domains on their page. **Add domain** on a client's page adds one already registered (for example, moved from another system) without an invoice; sync it to fetch its details. Each domain's page edits its dates, price and registrar, changes nameservers, gets the EPP code, and runs register, transfer, renew and sync by hand.
