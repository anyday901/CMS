# CMS

A free, self-hosted billing, client portal and support platform. It is an open source alternative to WHMCS.

> Status: early development. Not ready for production use yet.

## What it does (v1 goals)

- Client accounts and a client portal
- A public store: product catalogue, cart and checkout with sign-up, add-ons, promotion codes, upgrades and downgrades, free trials, billing on a fixed day of the month, stock control, a pending orders queue, order confirmation emails, and client cancellation requests
- Products, services and recurring billing
- Invoices, payments, credits, refunds and overdue handling
- Payment gateways: PayPal, Venmo (through PayPal) and Cash App Pay (through Square)
- Provisioning through manual fulfillment tasks or webhooks, with a module interface for control panels later
- Support tickets with email piping
- An importer for existing WHMCS installs

The full v1 spec is in [docs/spec.md](docs/spec.md). Progress is tracked in [TODO.md](TODO.md) and released changes in [CHANGELOG.md](CHANGELOG.md).

## Requirements

- PHP 8.3 or newer with the bcmath, curl, intl, mbstring, mysql, sqlite3, xml and zip extensions
- Composer, and Node 20.19 or newer (Node 22 recommended)
- MySQL 8 / MariaDB 10.6 or newer (SQLite works for local development)

On Ubuntu 24.04:

```sh
sudo apt install php8.3-cli php8.3-bcmath php8.3-curl php8.3-intl php8.3-mbstring \
  php8.3-mysql php8.3-sqlite3 php8.3-xml php8.3-zip unzip
```

Ubuntu's own `nodejs` package is Node 18, which is too old to build the frontend. Install Node 22 from NodeSource instead:

```sh
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

## Local development

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install && npm run build
php artisan admin:create
php artisan serve
```

Then open http://localhost:8000/admin and log in with the staff account you just created (it is an admin). To reach it from another machine, run `php artisan serve --host=0.0.0.0` and allow port 8000 through your firewall.

Run the tests with `php artisan test`.

## Installing on a server

[docs/install.md](docs/install.md) walks through a production install on Ubuntu with Nginx, PHP-FPM, MariaDB, cron for the scheduler and a systemd queue worker, plus updates and backups. It also covers the included Docker Compose setup (`Dockerfile` and `docker-compose.yml`), which runs the app with Nginx, MariaDB, Redis, a queue worker and the scheduler.

## Moving from WHMCS

`php artisan whmcs:import` copies clients and contacts, products with prices and configurable options, custom fields, services, invoices, payments, refunds, credit balances, ticket departments, tickets and replies (and, with `--attachments`, ticket attachments) from a WHMCS database into a new install. Clients, services, invoices and tickets keep their WHMCS ids, so invoice and ticket numbers stay the same. Set the `WHMCS_DB_*` settings in `.env` to a read-only connection or a restored dump first.

Or use the walkthrough in the admin area under **Settings > Import from WHMCS**: it checks the install is empty, connects to WHMCS (from `.env` or details you type in), runs the dry run and shows its report, then imports and lists the next steps. It runs the same importer; on very large installs the command line is more reliable than a browser request.

- `--dry-run` imports everything, compares totals with WHMCS (recurring revenue, balance due, payments and credit, per currency) and then undoes it, saving a report of every skipped record to `storage/app/whmcs/`.
- Clients log in with their WHMCS password. It is checked once against the old WHMCS hash and then stored again with this app's own hashing.
- Replies to old WHMCS ticket emails, with `[Ticket ID: 123456]` in the subject, still reach the right ticket.
- `php artisan whmcs:compare --simulate` checks that this app would bill the same renewals as WHMCS for a period after the import, without saving anything, so both can run side by side for a cycle before you switch.

The full steps, and what changes for clients, are in [docs/install.md](docs/install.md#moving-from-whmcs).

## Client portal

Clients log in at `/portal` to see their services and invoices, update their details and add contacts. Clients you add in the admin area start without a password: use **Email portal password link** on their page, or have them use **Forgot or never set your password?** on the login page. Set the `MAIL_*` settings in `.env` so these emails are delivered; with the default `MAIL_MAILER=log` they are written to `storage/logs/laravel.log` instead. Client emails are sent from the queue, so run a queue worker too (see [Client emails](#client-emails)).

Clients open and follow support tickets under **Support**.

## Store and ordering

The public store is at `/store`. It lists active products by product group (set up groups under **Products > Groups**), with add-ons and hidden or unpriced products left out. Anyone can pick a product, its billing cycle, configurable options, add-ons and a hostname, and add it to the cart at `/cart`. At checkout a new client creates their account (name, address, email and password); an existing client logs in first. Each checkout creates the services, pending, on one invoice, and they activate when it is paid. An order with nothing to pay activates at once. Logged-in clients reach the store from **Order** in the portal.

- **Add-ons** are products marked as add-ons on the product form, then ticked under **Add-ons offered with this product** on the products they go with. Each add-on becomes its own service linked to the main one, billed on the same cycle (or once, if that's how it's priced). Clients can also add one later from the service page. Add-ons are suspended, unsuspended and terminated with their service.
- **Promotion codes** are set up under **Products > Promotions**: a percent or a fixed amount off the price of the products a code covers (not setup fees), on the first payment only or on every renewal, with optional start and expiry dates, a maximum number of uses, and a once-per-client limit. The discount shows as its own line on invoices.
- **Upgrades and downgrades**: tick the products a service can move to under **Clients can upgrade or downgrade to** on the product form. Clients choose **Upgrade or downgrade** on the service page and pick the new product and its options; the billing cycle and due date stay the same. The rest of the paid period is prorated by day: if the new product costs more, the difference is invoiced and the move happens once it's paid; if it costs less, the move happens at once and the difference goes to the client's credit. Services with an unpaid invoice wait until it's settled. Modules that implement `App\Provisioning\ChangesPackage` (both built-in ones do) get a `change_package` action after the move.
- **Staff upgrades and downgrades**: **Upgrade or downgrade** on a service's admin page moves it to any product and any recurring billing cycle the product is priced on, with the new product's options. With **Charge or credit the difference** ticked, the move is prorated as above; if the billing cycle changes too, the unused time is credited and a whole period of the new cycle is invoiced from today, which becomes the start of the new billing period. Untick it to move at once without charging, keeping the next due date and billing the new price from the next renewal. A change of billing cycle alone doesn't run the provisioning module.
- **Cancellation requests**: clients choose **Cancel this service** on the service page in the portal, staff use **Request cancellation for the client** on the admin service page. A request is for straight away or for the end of the billing period (the next due date). Either way the service isn't invoiced again, and its unpaid charges come off invoices with nothing paid on them (an invoice with nothing else on it is cancelled). An end-of-period request is carried out by the nightly `billing:run` on the due date; until then the client or staff can withdraw it. Cancelling marks the service and its add-ons cancelled and runs the module's terminate action. Upcoming cancellations are listed on the dashboard.
- **Billing on a fixed day**: set **Bill on day of the month** (1 to 28) on the product form and every new service of it renews on that day. The first invoice covers the days from the order to that day, prorated from the cycle's price; with **Also charge the next period from day** set, orders on or after that day pay the short period and the next whole cycle together. Add-ons without a billing day of their own follow the product they're ordered with.
- **Free trials**: set **Free trial days** on the product form. A client's first service of that product on a recurring cycle is free for that many days: the order invoice shows the trial at no charge (plus any setup fee), the service starts at once, and the first renewal invoice comes when the trial ends. A client who has had the product before pays from the start. Upgrades and downgrades open once the trial is over.
- **Stock control**: tick **Count stock** on the product form and set how many are in stock. Each store order takes one per service, the store shows the product as out of stock at none, and the cart and checkout stop orders for more than is left. Cancelling an order or marking it as fraud puts its stock back.
- **Orders**: every checkout is saved as an order. **Orders** in the admin area lists them by status and finds them by order number or the client's name, company or email; a client's page shows their latest orders.
- **Pending orders**: every checkout lands in the queue under **Orders** (also on the dashboard), oldest first. Staff with billing access **Accept** an order, **Cancel** it, or **Mark as fraud**. Cancelling or flagging cancels the unpaid invoice, cancels services that haven't started and terminates running ones, and gives back the stock and promotion code use; a paid invoice is left for staff to refund. By default services still start as soon as their invoice is paid; tick **Hold new orders for review** on the **Settings** page (or set `BILLING_HOLD_NEW_ORDERS=true`) to keep a paid order's services pending until it's accepted.

## Online payments

Clients pay each invoice from the portal. A payment method appears on unpaid invoices once its settings are in `.env`. Leave them blank to hide it.

### PayPal and Venmo

1. In the [PayPal developer dashboard](https://developer.paypal.com/dashboard/applications), create a REST app and copy its client ID and secret.
2. Add a webhook to that app pointing at `https://your-domain/webhooks/paypal`, subscribed to **Payment capture completed** and the **Customer dispute created**, **updated** and **resolved** events, and copy its webhook ID.
3. Set these in `.env`:

```dotenv
PAYPAL_MODE=sandbox        # "live" for real payments
PAYPAL_CLIENT_ID=...
PAYPAL_SECRET=...
PAYPAL_WEBHOOK_ID=...
PAYPAL_VENMO=true          # show the Venmo button to US clients
```

Venmo payments go through PayPal and show as "Venmo" on the invoice. Disputed PayPal and Venmo payments are flagged on the admin invoice page; a dispute PayPal resolves in the buyer's favour, or one you accept, is recorded as a refund of the disputed amount. Answer disputes in the PayPal Resolution Center.

### Cash App Pay

1. In the [Square developer dashboard](https://developer.squareup.com/apps), create an application and copy its application ID and access token, plus the location ID of the location that should receive payments.
2. Set these in `.env`:

```dotenv
SQUARE_ENVIRONMENT=sandbox # "production" for real payments
SQUARE_APPLICATION_ID=...
SQUARE_ACCESS_TOKEN=...
SQUARE_LOCATION_ID=...
```

3. To keep refunds and disputes in sync, add a webhook subscription in the Square developer dashboard pointing at `https://your-domain/webhooks/square`, with the `refund.created`, `refund.updated`, `dispute.created` and `dispute.state.updated` events. Copy its signature key into `.env`:

```dotenv
SQUARE_WEBHOOK_SIGNATURE_KEY=...
# Only if the app is behind a proxy and its own URL differs from the one in Square:
# SQUARE_WEBHOOK_URL=https://your-domain/webhooks/square
```

With the webhook, refunds issued from the Square dashboard are recorded on the invoice, pending refunds are confirmed or undone as soon as Square knows, and disputed payments are flagged on the admin invoice page. A lost or accepted dispute is recorded as a refund of the disputed amount.

Test with sandbox credentials first; both providers have sandbox accounts for fake payments.

## Look and feel

The admin area and client portal share one theme, Harbor, defined in `resources/css/app.css`. Views use `brand` colors for buttons and links and `ink` colors for text, borders and backgrounds, so changing those values (and the fonts and corner sizes next to them) restyles the whole app. Run `npm run build` after editing it. The Figtree and Bricolage Grotesque fonts come from npm and are served by the app itself.

## Admin area

The admin area at `/admin` covers the dashboard, clients and their contacts, products with pricing, configurable options, groups, add-ons and upgrade paths, billing days, free trials and stock, promotion codes, orders and the pending orders queue, custom fields, services (add, upgrade or downgrade, change billing cycle, suspend, unsuspend, terminate, cancel), logging in to the portal as a client, manual fulfillment tasks, invoices (create, edit, view, download as PDF, record payments, apply credit, refund, cancel), support tickets, tax rules, client emails and templates, staff and the activity log.

### Searching clients

**Clients** searches by client or company name, email, phone and status; **Advanced** adds address, city, state, postcode, country, currency, credit balance, tax exempt and signup date. Closed clients are hidden from the list until you switch off **Hide closed clients** or pick **Closed** as the status. The search is kept in the web address, so a filtered list can be bookmarked or shared with other staff.

### Contacts

A client can have contacts: other people on the account, such as a bookkeeper or a developer. Staff add them on the client's page, and clients add them under **Account** in the portal. Each contact can be copied on invoice emails (new invoices, reminders and receipts), on support emails (ticket received and ticket replies), or both. A contact who gets support emails can also open and reply to tickets by email for that client. Contacts can't sign in to the portal.

### Configurable options

Products can have configurable options, set under **Products > (product) > Configurable options**: a dropdown of choices (such as disk size), a checkbox (such as backups) or a quantity (such as extra IP addresses, with a minimum and maximum). Each choice has a price per billing cycle and a setup fee, in the billing currency; a blank price is free. When staff add a service they pick its options, which are added to the recurring amount and listed on its invoices, and their setup fees are added to the first invoice. Staff can change a service's options later on the service page; the price difference applies from the next invoice. Options in use by a service can't be deleted. Clients billed in another currency can only be given free options.

### Custom fields

Add extra fields to clients and services under **Settings > Custom fields**: text, a text area, a dropdown or a checkbox, optionally required. A service field can be for every product or one product. Fields marked staff only are hidden from clients; the others show on the client's portal account page (where they can fill them in) and on their service pages (read only). Service fields and options are sent to provisioning modules, including the webhook module.

### Logging in as a client

Staff who can manage clients can use **Log in as client** on a client's page to see the portal as that client, for example to check what they see or to help them through a step. A banner shows while you are signed in as the client, and anything you do is recorded in the activity log under your own name. **Back to admin**, or logging out of the portal, signs you out of the client account and takes you back to their page, still signed in to the admin area. Closed accounts can't be opened this way.

### Staff roles

Each staff account has one role:

| Role | Can do |
| --- | --- |
| Admin | Everything, including staff, products, tax rules, departments, email templates, the email log, settings and the activity log |
| Billing | Create and edit clients, services and invoices; record payments, apply credit and refund; handle tickets |
| Support | Handle tickets; look at clients, services and invoices, and download invoice PDFs, without changing them |
| Custom | Only the abilities you tick: manage staff, manage settings, manage clients and services, manage invoices and payments, handle tickets |

Every role can look at clients, services, invoices and tasks. A custom staff member with nothing ticked can only look.

Admins add and edit staff under **Staff**. You can't delete your own account or remove your own admin role, so there is always at least one admin. From the command line, `php artisan admin:create --role=billing` creates a staff account with a role (the default is `admin`). Staff accounts that existed before roles were added are admins.

### Settings

Admins change late fees, tax-inclusive pricing, whether invoice emails attach the PDF, and how long to keep the activity log under **Settings**, which also links to ticket departments and email templates. Values saved there replace the matching `.env` settings below. With tax-inclusive pricing on, line amounts already include tax: an invoice's total is the sum of its lines, and the invoice shows how much of it is tax. Invoices keep the setting they were created with.

### Provisioning modules

A provisioning module sets up and manages services somewhere else, such as a control panel, a VPS host or your own API. Write a class that implements `App\Provisioning\ProvisioningModule`, add it to `config/provisioning.php`, then choose it on a product and fill in its settings there. The module's `create` runs when a service becomes active (its first invoice is paid, or staff add it without an invoice), and `suspend`, `unsuspend` and `terminate` follow the service's status. A module that also implements `App\Provisioning\ChangesPackage` gets `changePackage` when a service is upgraded or downgraded. Each action runs on the queue, so run a queue worker in production (`php artisan queue:work`, kept running by systemd or Supervisor).

Actions for one service run one at a time, in the order they happened. A service's page shows the last action and any error. Failed actions are not retried on their own, because repeating a half-finished action on another system can do more harm than good; fix the cause and use **Run again**. Products without a module keep working as before.

Two modules come with the app:

- **Manual fulfillment** opens a task under **Tasks** for each action, with a checklist you write per product (one step per line, for set-up, suspend, unsuspend, terminate and upgrade or downgrade). Staff tick the steps, add notes and mark the task done. Open tasks are listed on the dashboard and counted in the menu.
- **Webhook** sends each action as a JSON POST to a URL you set on the product. The body has the action, the service (including anything saved from earlier replies) and the client. With a signing secret, the `X-Webhook-Signature` header is `sha256=` plus the hex HMAC-SHA256 of the raw body. `X-Webhook-Delivery` stays the same when staff run a failed action again, so the receiver can ignore work it already did when only its reply was lost. Any 2xx reply counts as done; a JSON reply can include `message`, shown to staff, and `data`, an object saved on the service and sent with later actions.

### Staff emails

Staff are emailed when something needs a person: a provisioning action fails or a new manual task opens (staff who can manage clients: admin and billing roles), a payment is disputed or a dispute changes (staff who can manage billing), or a client opens or replies to a ticket (the ticket's assignee, or everyone who handles tickets when nobody is assigned). Emails go from the queue through the `MAIL_*` settings in `.env`.

### Support tickets

Add at least one department under **Settings > Departments** (for example Sales and Technical) so clients can open tickets. Give a department an email address to make it the reply-to on ticket emails and to accept tickets by email.

Clients open tickets in the portal under **Support**, choosing a department and priority, and can attach up to 5 files of 10 MB each (images, PDFs, text, office documents or zip). Staff see them under **Tickets**, which lists tickets waiting on staff first, most urgent and longest waiting at the top, and can filter by status, department and "assigned to me". On a ticket, staff reply (emailed to the client), add internal notes (never shown to or emailed to the client), and change the status, priority, department and assignee. A client reply reopens a closed ticket. Save answers you give often under **Tickets > Canned replies**; on a ticket, pick one from **Canned reply** to add it to your reply, with placeholders like `{client_first_name}`, `{ticket_id}` and `{staff_name}` filled in. Attachments are stored privately in `storage/app/private/tickets` and are only downloaded by staff and the ticket's client.

### Tickets by email

Clients can open and reply to tickets by email. Ticket emails carry `[Ticket #N]` in the subject and a "Reply above this line" marker, and use the department's address as the reply-to. When an email arrives:

- a subject with `[Ticket #N]` from the address of that ticket's client (or a contact of theirs who gets support emails) adds a reply to it;
- otherwise, an email from a client's address (or such a contact's) to a department's address opens a new ticket in that department;
- anything else (unknown senders, auto-replies, bulk mail) is ignored and noted in the Laravel log.

Quoted text below the marker or an "On ... wrote:" line is dropped, and attachments of other types or over 10 MB are skipped. Anyone can fake a sender address, so treat tickets by email with the same care as any email.

Deliver mail to the app in one of two ways:

1. **Pipe from your mail server.** Forward each department address to a command, for example in `/etc/aliases` with Postfix: `support: "|/usr/bin/php /path/to/cms/artisan tickets:pipe"`. Run it as a user that can write to `storage/`. Add `--department=ID` to put new tickets in a set department when the address the email was sent to isn't a department address (such as a catch-all).
2. **POST over HTTP.** Set `TICKETS_INBOUND_SECRET` in `.env`, then have your mail service POST the raw email (the full MIME message) as the request body to `https://your-domain/webhooks/email`, with the secret in an `X-Inbound-Secret` header or as `?key=`. `?department=ID` works like `--department`. The endpoint is off while the secret is blank.

### Client emails

The app emails clients when they place an order in the store (an order confirmation listing what they ordered, and the order's invoice with its own template), when an invoice is issued (with the PDF attached, unless turned off on the **Settings** page), when an invoice becomes overdue, when an invoice is paid in full, when a service is activated (the welcome email), when they ask for a password link, and when a ticket is opened or staff reply to it. Draft invoices are never emailed. To add product-specific instructions to the welcome email, such as a control panel address, fill in **Welcome email text** on the product; it goes where the template says `{product_welcome}`.

Change the subject and wording of each email under **Emails > Email templates**. Bodies are Markdown with placeholders such as `{client_first_name}` and `{invoice_url}`; each template lists the ones it can use. You can turn an email off, or reset it to the default. HTML typed into a template or a ticket reply is shown as text.

Every client email is listed under **Emails** with its status (queued, sent or failed) and full text, and a client's page shows their 10 most recent. Emails are sent from the queue and retried up to three times; staff can use **Send again** on a failed one. Password links are not kept in the log, so those can't be resent: send a new link instead. Run a queue worker in production (`php artisan queue:work`, kept running by systemd or Supervisor) or emails stay queued.

### Invoices

Use **New invoice** on a client's page to bill anything that isn't a service renewal. Add as many lines as you need; negative amounts work as discounts, but the total must be above zero. **Save as draft** keeps the invoice hidden from the client until you **Publish** it; **Create invoice** makes it visible and payable right away. Draft and unpaid invoices can be edited, but not below what has already been paid. Lines for a service renewal can be changed but not removed, because they tell the billing run that period is already invoiced; cancel the invoice instead.

Staff and clients can download any invoice they can see as a PDF. The company details at the top of the PDF come from `.env`:

| Setting | Default | Meaning |
| --- | --- | --- |
| `BILLING_COMPANY_NAME` | `APP_NAME` | Your business name |
| `BILLING_COMPANY_ADDRESS` | (blank) | Postal address; wrap it in double quotes and use `\n` for new lines |
| `BILLING_COMPANY_EMAIL` | (blank) | Billing contact email |
| `BILLING_COMPANY_TAX_ID` | (blank) | Tax or business registration number |

### Activity log

Admins can see who did what under **Activity**: staff and client logins, changes to clients, products, tax rules and staff, invoices created, edited, published and cancelled, payments, refunds, late fees, service status changes and ticket activity. Each entry records the person (or "System" for the nightly billing run and payment webhooks) and their IP address. A client's page shows their 20 most recent entries.

Entries are kept forever unless you set a number of days on the **Settings** page or with `ACTIVITY_RETENTION_DAYS`. `php artisan activity:prune` deletes older entries, and the scheduler runs it every night.

### Tax

Add tax rules under **Tax**. Each rule has a rate and optionally a country, or a country and state. A new invoice uses the most specific rule matching the client's address, and taxes only products marked taxable. Mark a client tax exempt on their edit page. Invoices keep the rate they were created with, so changing a rule doesn't change existing invoices.

### Credit and refunds

Overpayments and refunds to credit go to the client's credit balance. Staff with the billing or admin role can add or remove credit by hand under **Adjust credit** on a client's page, with a reason. Every change to the balance, by hand or automatic, is listed in the client's credit history, and clients see theirs on the portal home page. The balance can't go below zero. New invoices are paid from credit automatically (turn this off with `BILLING_APPLY_CREDIT=false`), and staff or the client can apply credit to an unpaid invoice by hand. Credit payments don't count as income on the dashboard.

To refund a payment, open its invoice and use **Refund** under the payment. PayPal, Venmo and Cash App payments can be sent back through the gateway (if the gateway reports the refund as pending, the nightly billing run checks it and undoes the record if it later fails); any payment can be recorded as refunded outside the app or moved to account credit. A fully refunded invoice is marked refunded. Services are left as they are, so suspend or terminate them yourself if needed.

## Scheduled jobs

Add the Laravel scheduler to cron so billing and activity log clean-up run daily (see [docs/install.md](docs/install.md#6-cron-for-the-scheduler)):

```cron
* * * * * cd /path/to/cms && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan billing:run` carries out end-of-period cancellation requests, generates renewal invoices, adds late fees, suspends or terminates overdue services and emails overdue reminders. Its settings are in `config/billing.php` and can be set from `.env`:

| Setting | Default | Meaning |
| --- | --- | --- |
| `BILLING_CURRENCY` | `USD` | Currency for new clients |
| `BILLING_INVOICE_DAYS_BEFORE_DUE` | `7` | Days before the due date to create the renewal invoice |
| `BILLING_SUSPEND_AFTER_DAYS` | `3` | Days overdue before suspending |
| `BILLING_TERMINATE_AFTER_DAYS` | `30` | Days overdue before terminating |
| `BILLING_APPLY_CREDIT` | `true` | Pay new invoices from the client's credit balance |
| `BILLING_LATE_FEE_AFTER_DAYS` | (off) | Days overdue before adding a one-time late fee |
| `BILLING_LATE_FEE_TYPE` | `fixed` | `fixed` for a set amount, `percent` for a share of the invoice total |
| `BILLING_LATE_FEE_AMOUNT` | `0` | The fee, e.g. `5.00`, or `10` for 10% |
| `BILLING_TAX_INCLUSIVE` | `false` | Prices include tax |
| `BILLING_REMINDER_DAYS` | `1,7` | Days overdue to email a reminder, comma separated; blank for none |
| `BILLING_ATTACH_INVOICE_PDF` | `true` | Attach the PDF to new invoice emails |
| `BILLING_HOLD_NEW_ORDERS` | `false` | Keep the services of paid store orders pending until staff accept the order |
| `ACTIVITY_RETENTION_DAYS` | (forever) | Days to keep activity log entries |
| `TICKETS_INBOUND_SECRET` | (off) | Secret for tickets by email over HTTP |

Late fees are added once per invoice and are not taxed. Each reminder is sent once per invoice, and a reminder skipped on a missed night is sent on the next run. The late fee, tax-inclusive, invoice PDF, order review and activity log settings can also be changed on the admin **Settings** page.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
