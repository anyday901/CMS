# CMS

A free, self-hosted billing, client portal and support platform. It is an open source alternative to WHMCS.

> Status: early development. Not ready for production use yet.

## What it does (v1 goals)

- Client accounts and a client portal, with client groups, staff notes, merging duplicate accounts and marketing email consent
- Admin tools: a search box across clients, services and domains, invoices, orders and tickets, and a staff to-do list
- A public store: product catalogue, cart and checkout with sign-up, add-ons, promotion codes, upgrades and downgrades, free trials, billing on a fixed day of the month, stock control, a pending orders queue, order confirmation emails, and client cancellation requests
- Products, services and recurring billing
- Invoices, payments, credits, refunds and overdue handling
- Payment gateways: PayPal, Venmo (through PayPal) and Cash App Pay (through Square)
- Provisioning through manual fulfillment tasks, webhooks, or cPanel / WHM, Plesk, DirectAdmin and Proxmox VE servers, with servers grouped and filled least used first or one at a time, accounts copied between servers on a move, disk and bandwidth usage with overage billing, and server status monitoring
- Software licensing: license keys your own software checks, locked to where they are installed
- Support tickets with email piping, a spam filter, tickets from people without an account (by email or a contact form), custom fields per department, response time targets (SLAs) in calendar or business hours, escalation rules, tags, merging and splitting, auto-close and feedback ratings
- A knowledge base with search, announcements (both translatable, knowledge base categories too), a network status page and a downloads area
- Account security: two-factor authentication for clients and staff, contacts with their own logins and permissions (one login can reach several accounts, with remember-me), emails about logins from new devices, email verification, login history, failed login lockout, IP bans, CAPTCHA on public forms, and GDPR data export and anonymising
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

`php artisan whmcs:import` copies servers and server groups, client groups, clients and contacts (a WHMCS 8 user of several clients gets one login for all of them), staff notes on clients, marketing email consent, the to-do list, products with prices, configurable options and module settings, custom fields, services with their server and login, invoices, payments, refunds, credit balances with the credit log, promotions and the orders that used them, cancellation requests, the activity log, admins as staff accounts, ticket departments, custom fields, tags, tickets (including ones from people without a client account) and replies (and, with `--attachments`, ticket attachments), ticket feedback ratings, the knowledge base and announcements with their translations, network issues, downloads (with `--downloads` pointing at WHMCS's downloads folder) and escalation rules from a WHMCS database into a new install. With `--hash=` set to the `$cc_encryption_hash` from WHMCS's `configuration.php`, server and service passwords come over too. Clients, services, invoices and tickets keep their WHMCS ids, so invoice and ticket numbers stay the same. Set the `WHMCS_DB_*` settings in `.env` to a read-only connection or a restored dump first.

Or use the walkthrough in the admin area under **Settings > Import from WHMCS**: it checks the install is empty, connects to WHMCS (from `.env` or details you type in), runs the dry run and shows its report, then imports and lists the next steps. It runs the same importer; on very large installs the command line is more reliable than a browser request.

- `--dry-run` imports everything, compares totals with WHMCS (recurring revenue, balance due, payments and credit, per currency) and then undoes it, saving a report of every skipped record to `storage/app/whmcs/`.
- Clients log in with their WHMCS password. It is checked once against the old WHMCS hash and then stored again with this app's own hashing.
- Replies to old WHMCS ticket emails, with `[Ticket ID: 123456]` in the subject, still reach the right ticket, including tickets that were merged in WHMCS.
- WHMCS admins become staff accounts, unless a staff account with the same email already exists (such as yours). Full Administrators, and roles with every permission they have, get the Admin role. Other roles get what their WHMCS permissions add up to: Edit Clients Details and Edit Clients Products/Services give managing clients and services, Manage Invoice and Add Transaction give billing, View Support Ticket gives tickets, Configure General Settings gives settings, and Configure Administrators and Configure Admin Roles give staff. When that matches a built-in role (Billing or Support) the admin gets it, otherwise the custom role with just those abilities; the import report says what each admin got and why. A WHMCS too old to keep role permissions falls back to the closest built-in role by name (Account Manager becomes Billing, anything else Support). Check roles under **Staff**. Disabled admins are left out. Admins keep their WHMCS password; the few with an older kind of hash get a random one until you set theirs. Imported tickets keep their assignee, and staff replies are linked to the staff member who wrote them.
- Escalation rules come over turned off, so you can check them first. Knowledge base articles that were for logged-in clients only come over unpublished, since articles here are public.
- Feedback ratings come over out of 5 instead of WHMCS's 10 (halved and rounded up). WHMCS rates each admin who replied; here a ticket has one rating, the average of those.
- Tickets from people without a client account come over as guest tickets. Translations of articles, knowledge base categories and announcements come over, with their languages offered under **Settings > Languages** and WHMCS's default language as the main one.
- The history reports read comes over (orders, promotions and their usage, the credit log, cancellation requests and the activity log, as well as invoices, payments, services and tickets), so **Reports** covers the years before the switch. Orders keep their WHMCS ids, and the order number WHMCS showed clients is shown next to it and found by search. Promotions WHMCS had as price overrides or free setup come over switched off. Cancellation requests for services WHMCS hadn't cancelled yet come over waiting, so the billing run carries them out on their date; withdraw any you don't want first.
- Custom WHMCS reports (PHP files in `modules/reports`) can't be copied, since the importer only reads the database. The walkthrough lists the closest built-in report for each of WHMCS's own.
- `php artisan whmcs:compare --simulate` checks that this app would bill the same renewals as WHMCS for a period after the import, without saving anything, so both can run side by side for a cycle before you switch.

The full steps, and what changes for clients, are in [docs/install.md](docs/install.md#moving-from-whmcs).

## Client portal

Clients log in at `/portal` to see their services and invoices, update their details and add contacts. Clients you add in the admin area start without a password: use **Email portal password link** on their page, or have them use **Forgot or never set your password?** on the login page. Set the `MAIL_*` settings in `.env` so these emails are delivered; with the default `MAIL_MAILER=log` they are written to `storage/logs/laravel.log` instead. Client emails are sent from the queue, so run a queue worker too (see [Client emails](#client-emails)).

Under **Security**, clients turn on two-factor authentication and see recent logins to their account, and they can download a copy of their data under **Account**. Contacts the client gave a login sign in here too, with their own email and password, and see only what they were allowed (see [Contacts](#contacts)).

Clients open and follow support tickets under **Support**, which also links the knowledge base, announcements, downloads and network status. Those four pages are public, so visitors who aren't logged in can read them from **Help** in the store menu.

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

The admin area at `/admin` covers the dashboard, a search box for everything, a staff to-do list, clients with their contacts, groups and notes (and merging two accounts), the knowledge base, announcements, network status and downloads (under **Support**), products with pricing, configurable options, groups, add-ons and upgrade paths, billing days, free trials and stock, promotion codes, orders and the pending orders queue, custom fields, services (add, upgrade or downgrade, change billing cycle, suspend, unsuspend, terminate, cancel), logging in to the portal as a client, manual fulfillment tasks, invoices (create, edit, view, download as PDF, record payments, apply credit, refund, cancel), support tickets, tax rules, client emails and templates, staff, account security (login history, IP bans, CAPTCHA, GDPR export and anonymising) and the activity log.

### Searching clients

**Clients** searches by client or company name, email, phone and status; **Advanced** adds address, city, state, postcode, country, currency, client group, credit balance, tax exempt, agreed to marketing emails and signup date. Closed clients are hidden from the list until you switch off **Hide closed clients** or pick **Closed** as the status. The search is kept in the web address, so a filtered list can be bookmarked or shared with other staff. **Export to CSV** downloads the clients found, with their group, marketing consent and a personal unsubscribe link, for a mailing list or a spreadsheet. Values that a spreadsheet would run as a formula (starting with `=`, `+`, `-` or `@`) start with an apostrophe.

The **Search** box in the admin menu looks through everything at once: clients (by name, company, email, phone or number, or a contact's name or email), services and domains (by domain or label, control panel username, product or number), invoices and orders (by number) and tickets (by number, subject or a guest's email). Staff only see tickets if they can work on them.

### Client groups, notes and the to-do list

**Settings > Client groups** holds groups such as resellers or VIPs, each with a colour. Put a client in one on their edit page; the group shows as a label on their page, in the clients list and in search results, and the clients search filters by it. Groups are labels only: discounts and exemptions from automatic suspension aren't tied to them.

A client's page has **Notes**: dated notes by staff, which only staff see. Tick **Pin to the top of the page** to show one as a banner above the client's details. The older single notes field is still on the edit form as **Profile notes**.

**To-do** in the admin menu is a to-do list for staff. Each item can be for one staff member or anyone, have a due date and details, and be about a client (add one from the client's page). The list shows your items by default, with tabs for everyone's, unassigned, overdue and done ones, and a tick box marks an item done. Your items, and unassigned ones, show on the dashboard, and the menu counts the ones still to do.

### Merging clients

For a client with two accounts, open the one to get rid of and use **Merge**. Find the account to keep, check what will move, and confirm. Its services, invoices, payments, orders, tickets, contacts, notes, to-dos, emails, login history and activity move to the account you keep, and its credit balance is added there with a line in the credit history. A contact with an email already on the kept account is combined with that contact. The merged account is deleted; its details go into a note on the kept account, and its account holder can be added as a contact. Accounts billed in different currencies can't be merged.

### Marketing email consent

Clients say whether they want news and offers by email with a box at sign-up (unticked unless they tick it) and under **Account > Email preferences** in the portal. Staff can change it on the client's edit form when a client asks. Every change is kept in the activity log, and the client's page shows when they last said yes or no. Each client has a signed unsubscribe link (in the CSV export) that works without logging in and supports mail apps' one-click unsubscribe. The WHMCS import brings over WHMCS's marketing opt-in.

### Mass mail

**Clients > Mass mail** emails many clients at once, picked by status, group, country, currency and the products they have. Preview it to see how many clients it reaches and how it reads. Marketing emails only go to clients who agreed to them, with their unsubscribe link and a one-click `List-Unsubscribe` header; service notices go to everyone picked. Emails go out from the queue.

### Contacts

A client can have contacts: other people on the account, such as a bookkeeper or a developer. Staff add them on the client's page, and clients add them under **Account** in the portal. Each contact can be copied on invoice emails (new invoices, reminders and receipts), on support emails (ticket received and ticket replies), or both. A contact who gets support emails can also open and reply to tickets by email for that client.

Tick **Can sign in to the portal** to give a contact their own login, and choose what they can do there: **invoices** (see and pay invoices, use credit), **orders** (place orders, upgrade and cancel services), **services** (see services, log in to control panels, change service passwords) and **support** (tickets). The contact gets an email with a link to choose a password, valid for a day; **Send invite again** on the contact, or **Forgot password** on the login page, sends a new one. A contact never sees the account's details or other contacts, and what they do is logged under their own name. A login email can't be a client's own email.

One person can sign in to several client accounts with one login, like WHMCS 8 users: add them as a contact with portal access on each account, with the same email. Each account keeps its own contact for them, with its own permissions and email copies, while the password, two-factor authentication and remember-me are shared. A contact who already signs in elsewhere isn't invited again; they get a **Contact added to another account** email instead. After logging in they pick an account, and the account name in the portal menu switches between them; they land on the one they used last. Changing a contact's email gives them a new login, with a new invitation.

Contacts can tick **Remember me** too. Their remember-me cookie signs them back in as the contact, never as the account holder, and ends when they log out or change their password. The WHMCS import gives sub-account contacts, and WHMCS 8 users of a client, a login with the matching permissions; a WHMCS 8 user of several clients gets one login for all of them.

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
| Billing | Create and edit clients, services and invoices; record payments, apply credit and refund; handle tickets; see reports |
| Support | Handle tickets; look at clients, services and invoices, and download invoice PDFs, without changing them |
| Custom | Only the abilities you tick: manage staff, manage settings, manage clients and services, manage invoices and payments, handle tickets, see reports |

Every role can look at clients, services, invoices and tasks. A custom staff member with nothing ticked can only look.

Admins add and edit staff under **Staff**. You can't delete your own account or remove your own admin role, so there is always at least one admin. From the command line, `php artisan admin:create --role=billing` creates a staff account with a role (the default is `admin`). Staff accounts that existed before roles were added are admins.

### Account security

**Settings > Security** (admins) holds:

- **Failed logins**: after 5 failed logins from one IP address to one account in 15 minutes, that address is locked out of that account for a while; an address that fails 20 logins in an hour, to any accounts, is banned for 24 hours. Lockouts only block the address the failures came from, so the owner can still log in elsewhere.
- **Staff two-factor authentication**: require it for everyone. Turn it on for your own login first, under **My account**.
- **New device emails**: on by default, clients and contacts get the **New device sign-in** email when someone signs in to the portal with their email from a browser that hasn't signed in with it before, with the time, browser and IP address. The first browser an email signs in from is just noted, and browsers unused for a year are forgotten, apart from the last one each email used, so a login after a long break is still emailed about.
- **CAPTCHA**: Cloudflare Turnstile, hCaptcha or Google reCAPTCHA v2 on the portal login, sign-up at checkout, forgot password, the contact form and (optionally) the staff login. Create a site with the provider for your domain and paste its site key and secret key.
- **IP bans**: ban an address or a range such as `203.0.113.0/24`, for some days or for good. Banned addresses can't open any page, but payment and email webhooks still get through.
- **Login history** for the portal and admin area, searchable by email or IP address. It keeps 90 days.

Everyone turns on two-factor authentication for their own login: clients and contacts under **Security** in the portal, staff under **My account** (their name in the admin menu). It works with any authenticator app and gives eight one-time recovery codes. Staff can turn it off for a client or contact (on the client's page) and admins for another staff member (on their staff page), for someone who lost their phone.

Clients who sign up at checkout or change their email are asked to confirm the address with a link. A client's page shows whether their email is verified, with buttons to send the link or mark it verified.

For GDPR requests, a client's page has **Export personal data** (a JSON file of everything kept about them, which clients can also download under **Account** in the portal) and, on a closed account with no running services, **Anonymise this client**. Anonymising removes the client's personal details, contacts, emails and login history and blanks their tickets, but keeps invoices, payments and services for your records. It can't be undone.

### Reports

**Reports** (Admin and Billing roles, or a custom role with **See reports**) has:

- Income: annual income by month, monthly transactions (payments, fees and refunds), daily performance, and an income forecast from active services' renewals.
- Sales: income by product, new orders and new clients by month, promotion usage, and top clients by income.
- Invoices: aging (not yet due, 1 to 30, 31 to 60, 61 to 90 and over 90 days overdue), credits given and used, and a statement for one client with a running balance.
- Tax: tax collected by country and rate, on invoices paid or issued in the dates, optionally only for EU clients (sales tax liability and EU VAT returns).
- Clients and services: clients by country and by group, client growth and churn, retention time by product, suspensions and cancellations by reason, and server revenue.
- Support: tickets opened and closed, first response and resolution times, replies by staff, ratings and feedback comments, and ticket tags.

Each report has a date range (with shortcuts such as last month or this year), a currency when you bill in more than one, and **Export to CSV**. Income counts payments less refunds by the day they were made; credit applied to invoices isn't counted again.

### Automation and cron status

**Settings > Automation** holds everything the nightly billing run works by: the time it runs, how many days before the due date renewal invoices are made, paying new invoices from credit, the days overdue reminders go out, late fees, the days before overdue services are suspended and terminated, and the days to pay an overage invoice. Values saved there replace the matching `BILLING_*` settings in `.env`.

**Settings > Cron status** shows whether the scheduler is running (with the crontab line to add when it isn't), each automatic task with when it runs, when it last ran, whether that worked and when it runs next, the queue's waiting and failed jobs, and the last 50 runs. Runs are kept 30 days.

### Integrations: webhooks, chat, SMS and the API

- **Settings > Webhooks** sends a signed JSON POST to your URLs when events happen (new orders, paid invoices, service changes, tickets and more). See [docs/webhooks.md](docs/webhooks.md).
- **Settings > Chat notifications** posts events to Slack, Discord or Microsoft Teams channels.
- **Settings > SMS** texts staff, and clients who agree to it, through Twilio or Vonage (or another provider you add in `config/sms.php`).
- **Settings > API keys** makes keys for the REST API under `/api/v1`, each limited to what it may do. See [docs/api.md](docs/api.md).

All of these are sent from the queue, so run a queue worker.

### Add-ons

**Settings > Add-ons** installs, configures and enables optional modules. The **Project management** add-on ships with the app: projects for clients with tasks, time tracking and a timer, and billable time put on a draft invoice. Developers can write their own add-ons and listen to events with the hook system; see [docs/addons.md](docs/addons.md).

### Settings

Admins change tax-inclusive pricing, whether invoice emails attach the PDF, and how long to keep the activity log under **Settings**, which also links to ticket departments and email templates. Values saved there replace the matching `.env` settings below. With tax-inclusive pricing on, line amounts already include tax: an invoice's total is the sum of its lines, and the invoice shows how much of it is tax. Invoices keep the setting they were created with.

### Provisioning modules

A provisioning module sets up and manages services somewhere else, such as a control panel, a VPS host or your own API. Write a class that implements `App\Provisioning\ProvisioningModule`, add it to `config/provisioning.php`, then choose it on a product and fill in its settings there. The module's `create` runs when a service becomes active (its first invoice is paid, or staff add it without an invoice), and `suspend`, `unsuspend` and `terminate` follow the service's status. A module that also implements `App\Provisioning\ChangesPackage` gets `changePackage` when a service is upgraded or downgraded, one that implements `App\Provisioning\ChangesPassword` gets `changePassword` when staff or the client give a running service a new password, one that implements `App\Provisioning\Reinstalls` gets `reinstall`, and one that implements `App\Provisioning\SingleSignOn` gives clients a **Log in to the control panel** button through `loginUrl`. A module that implements `App\Provisioning\CopiesAccounts` can copy an account to another of its servers itself when staff move it, `App\Provisioning\ReportsUsage` reports disk and bandwidth use, and `App\Provisioning\ReportsServerHealth` reports how a server is doing to status monitoring. Each action runs on the queue, so run a queue worker in production (`php artisan queue:work`, kept running by systemd or Supervisor).

A module still waiting on the other system, such as a VM whose disk is being copied, returns `ProvisioningResult::later($seconds)` and is run again after that wait, for up to an hour; it should save what it started (with the service's `provisioning_data`) so the next run picks up where it left off.

Actions for one service run one at a time, in the order they happened. A service's page shows the last action and any error. Failed actions are not retried on their own, because repeating a half-finished action on another system can do more harm than good; fix the cause and use **Run again**. Products without a module keep working as before.

These modules come with the app:

- **Manual fulfillment** opens a task under **Tasks** for each action, with a checklist you write per product (one step per line, for set-up, suspend, unsuspend, terminate and upgrade or downgrade). Staff tick the steps, add notes and mark the task done. Open tasks are listed on the dashboard and counted in the menu.
- **Webhook** sends each action as a JSON POST to a URL you set on the product. The body has the action, the service (including anything saved from earlier replies) and the client. With a signing secret, the `X-Webhook-Signature` header is `sha256=` plus the hex HMAC-SHA256 of the raw body. `X-Webhook-Delivery` stays the same when staff run a failed action again, so the receiver can ignore work it already did when only its reply was lost. Any 2xx reply counts as done; a JSON reply can include `message`, shown to staff, and `data`, an object saved on the service and sent with later actions.
- **cPanel / WHM** creates a cPanel account on the product's **WHM package** through WHM API 1, and suspends, unsuspends, removes, re-packages and changes the password of it. Log in as root or a reseller, preferably with an API token from **WHM > Development > Manage API Tokens**.
- **Plesk** makes a Plesk customer for each service (its login is the service's username and password) and a subscription for the service's domain on the product's **Service plan**, through the XML API. Log in as admin or a reseller with the password or a secret key (`plesk bin secret_key -c`), and set the IP address subscriptions are hosted on.
- **DirectAdmin** creates a DirectAdmin user on the product's **User package** and the server's shared IP address, as admin or a reseller with the password or a login key.
- **Proxmox VE** makes either VMs or LXC containers, by the product's **Type**. A VM is cloned from the product's cloud-init template (**Template VM ID**) with the product's CPU cores, memory and disk size, and gets its login user and password through cloud-init. A container is made from an OS template (**Container template**, such as `local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst`) with the cores, memory and root disk, on the product's **Network bridge** (vmbr0 by default), and the service's password for root. Give the server an API token (`user@realm!tokenid` as the username, the secret as the API token) with rights to clone, create, configure and power VMs and containers, or a user and password. With **Assigned IP addresses** on the server (one per line, like `203.0.113.10/24 203.0.113.1`), each one gets the first free address; without, VMs keep the template's network settings and containers use DHCP. Changing a VM's password needs the QEMU guest agent running in it; a container's can't be changed through Proxmox. Staff and clients can **Reinstall** either from its template, which erases it and makes it again with the same VM ID, address and password.
- **Software licensing** issues a license key for each service, for software you sell. Your software checks the key at `/api/licenses/check`, and the first checks lock it to the domain, IP address and directory it runs from (as the product's **Lock to** and **Installs per license** set). The key stops working while the service is suspended and expires when it ends. Clients see their key on the service page and can **Reissue** it to move the software, unless the product leaves that to staff. Answers are signed with the product's **Signing secret**; see [docs/licensing.md](docs/licensing.md) for the check and how to verify the answer.

The cPanel, Plesk, DirectAdmin and Proxmox modules were built from the vendors' API documentation and tested against mocked replies, not yet against live servers. Try them on a test server before selling with them.

#### Servers

Modules that set services up on servers use the servers under **Products > Servers**. Add each one with its module, host name, port (blank for the module's usual one), login and the most accounts it should hold; the password and API token are encrypted with the app key, and **Test connection** logs in with them. Untick **Active** to stop new accounts going on a server. A server can't be deleted while services are on it, or while an ended service's terminate is still waiting or failed, since removing that account needs the server's login.

Server groups say which servers a product uses and how they fill up: **least used** puts each new account on the server with the most room, **fill up** fills one server before using the next. Choose a group on the product; a product without one uses the least used active server of its module. A service is put on a server when it is created, and pending, active and suspended services count towards a server's accounts.

A new service gets a username made from its domain and a random password, unless staff set them first under **Change server or login** on the service page, where staff also see and change them. Choosing another server there doesn't move the account; it's for accounts moved another way. A new password for an account that exists is set on the server, and the client keeps the old one until that has worked. Clients see the control panel address (or IP address), username and password on the service's portal page once it's set up, can change the password there, and on cPanel, Plesk and DirectAdmin can press **Log in to the control panel** to open it already logged in. Hosting products on cPanel, Plesk and DirectAdmin ask for a domain in the store.

**Move to another server** on the service page moves an account between servers of its module: the account is created on the new server with the same domain, username and password, and the old one stays until you have copied the data over (with the panel's own transfer or backup tools) and press **Finish the move**, which removes it. Tick "nothing to copy" to remove the old one as soon as the new one is ready.

On cPanel and Proxmox, tick **Copy the account with its data automatically** to have the module copy it instead of making an empty account:

- **cPanel** runs WHM's transfer tool on the new server, which logs in to the old one over SSH as root (so the old server needs `root` as its username and the root password saved) and copies the account with its files, email, databases and password. The old account stays until you have checked the new one and finish the move.
- **Proxmox** migrates the VM or container with Proxmox itself. When both servers are in the same cluster (the same cluster name with the same member nodes and addresses), it moves to the new server's node (live for a running VM, with a restart for a running container), and as the VM itself moves there is nothing left to remove: the move finishes on its own. To another cluster it uses Proxmox's remote migration (Proxmox VE 7.3 or later), which needs an API token on the new server with rights to create VMs there, and keeps the old one stopped until you finish the move. The disks go to the product's **Storage** (local-lvm by default) and the network to its **Network bridge**. Either way the VM keeps its address, so check it suits the new server's network.

A big copy can take a while; the service page shows it copying, and it is given up to a day. If it fails, the error is on the service page: run **Copy account** again from **Run again**, or cancel the move. An upgrade or downgrade to a product of another server module, such as cPanel to Plesk, moves the account the same way, to a server of the new module; staff are emailed when the new account is ready to copy to.

#### Usage and usage billing

For cPanel, Plesk, DirectAdmin and Proxmox servers, `php artisan usage:collect` (run every hour by the scheduler) reads each account's disk use and its bandwidth so far this month, with the limits the panel sets. Staff and clients see them on the service page. Proxmox counts a VM's traffic since it started, so the month's bandwidth is added up from the change between readings; Proxmox only knows a VM's disk use through the guest agent, so without one only the disk size is shown.

To bill for use, tick **Usage billing** on a product and set how much disk and monthly bandwidth is included (in MB) and the price per GB over. After each month, `billing:run` invoices each running service for what it used over (its last disk reading and the month's bandwidth), on one invoice per client, due in `BILLING_OVERAGE_DUE_DAYS` days (7 by default). Prices are in the billing currency, so clients who pay in another currency aren't charged overages. An unpaid overage invoice suspends the service like any other.

#### Server status monitoring

`php artisan servers:check` (every five minutes from the scheduler) checks that each server answers on its module's port, and on any other ports listed in its **Also check these ports** box (such as 80, 443, 21 and 25). cPanel servers also report their load, and Proxmox each node's CPU, memory and uptime. A server whose module port doesn't answer is **down**; one with another port closed, or a Proxmox node offline, is in **warning**. A change counts once two checks in a row agree, then admins are emailed, the dashboard says so, and the outage is kept until it is over. The servers page shows each server's status, load and uptime over the last 30 days; **Check status** on a server's page checks it straight away. Untick the check under **Status monitoring** on a server to leave it out.

### Staff emails

Staff are emailed when something needs a person: a provisioning action fails or a new manual task opens (staff who can manage clients: admin and billing roles), a payment is disputed or a dispute changes (staff who can manage billing), or a client opens or replies to a ticket (the ticket's assignee, or everyone who handles tickets when nobody is assigned). Emails go from the queue through the `MAIL_*` settings in `.env`.

### Support tickets

Add at least one department under **Settings > Departments** (for example Sales and Technical) so clients can open tickets. Give a department an email address to make it the reply-to on ticket emails and to accept tickets by email.

Clients open tickets in the portal under **Support**, choosing a department and priority, and can attach up to 5 files of 10 MB each (images, PDFs, text, office documents or zip). Staff see them under **Tickets**, which lists tickets waiting on staff first, most urgent and longest waiting at the top, and can filter by status, department and "assigned to me". On a ticket, staff reply (emailed to the client), add internal notes (never shown to or emailed to the client), and change the status, priority, department, assignee and tags; **Tickets** can be filtered by tag. A client reply reopens a closed ticket. While a client types a new ticket, knowledge base articles that might answer it are suggested under the subject.

Staff can merge other tickets of the same client into the one they're on (their messages move over, they close, and their old numbers, portal links and email replies lead to the merged ticket), or tick messages and split them into a new ticket. Set **Close answered tickets after this many hours without a reply** under **Settings** to close tickets clients stop answering; `php artisan tickets:close-inactive` runs every 15 minutes and emails the client, who can reply to reopen. Save answers you give often under **Tickets > Canned replies**; on a ticket, pick one from **Canned reply** to add it to your reply, with placeholders like `{client_first_name}`, `{ticket_id}` and `{staff_name}` filled in. Attachments are stored privately in `storage/app/private/tickets` and are only downloaded by staff and the ticket's client.

### Ticket custom fields, SLAs and escalation rules

Under **Settings > Custom fields**, choose **Tickets** to add a field clients fill in when they open a ticket, for every department or one department (for example a domain name for Technical, or a budget for Sales). Clients pick the department first, then see its fields. Staff see and change every field on the ticket, including staff-only ones, and can leave a required field empty (such as on a ticket opened by email).

Each department can have **response time targets (SLA)**: the hours staff have to answer a ticket waiting on them, by priority. A ticket's response is due that long after the client's last message; a staff reply clears it and the next client reply starts it again. **Tickets** shows when each ticket is due, with an **Overdue** filter, and a ticket's page shows when it was first answered. Targets count every hour, unless the department is set to **Count business hours only**: then only the opening hours under **Settings > Business hours** count (hours for each weekday, closed dates such as public holidays, and a timezone).

A department can also **ask clients to rate the support** when a ticket is closed. When staff close a ticket, or it closes for inactivity, the client is emailed a link to rate it from 1 to 5 stars with a comment; a client who closes their own ticket sees the form there. **Tickets > Feedback** shows the average rating, how ratings spread, an average for each staff member who replied, and every rating with its comment.

**Tickets > Escalation rules** change open tickets automatically. A rule matches tickets by department, status and priority, and by how long since the last reply or whether the response is overdue; it can move them to another department, change their status or priority, assign them, email the assignee or chosen staff, and post a reply to the client in your business's name. `php artisan tickets:escalate` runs the rules every five minutes from the scheduler. A rule changes a ticket once, and again only after someone replies.

### Knowledge base, announcements, network status and downloads

These are under **Support** in the admin area, for staff who handle tickets.

- **Knowledge base** (`/knowledgebase`): articles written in Markdown, in categories one level deep, with search over titles, text and tags. Hidden categories and unpublished articles aren't shown. Each article counts its views and asks "Was this article helpful?".
- **Translations**: set the main language and the other languages (codes such as `fr`, `de` or `pt-BR`) under **Settings > Languages**, then translate an article, knowledge base category (its name and description) or announcement under **Translations** on its edit page. Visitors see the translation into the language their browser asks for, or pick a language at the top of the help pages; search and ticket suggestions match the translation too. Anything without a translation shows in the main language.
- **Announcements** (`/announcements`, with an RSS feed at `/announcements/rss`): dated news, which can be written ahead and shown from its date. The latest three show on the portal home page.
- **Network status** (`/network-status`): current issues and planned maintenance, each affecting a server or something described in words, with a status, priority, start and end. Clients with a service on an affected server see a notice on their portal home page. Tick **Show on the network status page** on a server (under **Status monitoring**) to list its name, status and 30-day uptime there; its host name and address aren't shown.
- **Downloads** (`/downloads`): files in categories, kept in `storage/app/private/downloads` and always sent as downloads. A file can be for everyone, for logged-in clients, or for clients with an active service of chosen products. Uploads are limited to 7.5 MB, and to PHP's `upload_max_filesize` and `post_max_size`.

### Tickets by email

Clients can open and reply to tickets by email. Ticket emails carry `[Ticket #N]` in the subject and a "Reply above this line" marker, and use the department's address as the reply-to. When an email arrives:

- auto-replies, bulk mail, bounces and email from your own addresses are ignored, and the spam filter (below) drops spam;
- a subject with `[Ticket #N]` from the address of that ticket's client (or a contact of theirs who gets support emails), or from the guest who opened it, adds a reply to it;
- otherwise, an email from a client's address (or such a contact's) to a department's address opens a new ticket in that department, and so does one from anyone else when guest tickets are on;
- anything else is ignored.

**Tickets > Email and spam log** lists every email that came in, what became of it and why, for 90 days.

### Guest tickets and the spam filter

Tick **Accept tickets from people without an account** under **Settings** to let anyone open a ticket, by emailing a department or with the contact form at `/support/contact` (the help pages link to it). The guest is emailed a private link to their ticket, where they read and answer replies and can close and rate it; they can also just reply to the emails. The form never shows that link, so only whoever reads the inbox can follow the ticket. Keep the **Ticket received** email template on, since it carries the link. Staff see the guest's name and address on the ticket, and can **Move to client account** once the guest has an account: the ticket then shows in the client's portal and the private link stops working.

The spam filter under **Settings > Ticket spam filter** checks piped email and the contact form. It drops email your mail server's spam checker (SpamAssassin or Rspamd) marked as spam, email from blocked addresses or domains (`@example.com` covers its subdomains too), email with blocked words in the subject or message, and new tickets past a limit per sender per hour (10 by default; replies aren't limited). The contact form also has a hidden field that catches bots. Spam on the form gets the same thank-you page as a real message. Everything caught is in **Tickets > Email and spam log**.

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
| `TICKETS_CLOSE_INACTIVE_HOURS` | (off) | Close answered tickets the client hasn't replied to in this many hours |
| `TICKETS_TIMEZONE` | `UTC` | Timezone of the business hours that SLA targets can count in |
| `TICKETS_GUEST_TICKETS` | `false` | Accept tickets from people without a client account |
| `HELP_MAIN_LANGUAGE` | `en` | Language knowledge base articles and announcements are written in |
| `SECURITY_LOCKOUT_ATTEMPTS` | `5` | Failed logins from one address to one account before a lockout |
| `SECURITY_LOCKOUT_MINUTES` | `15` | How long those failures count, and so the lockout lasts |
| `SECURITY_BAN_AFTER_FAILURES` | `20` | Failed logins from one address in an hour before it is banned; blank for no automatic bans |
| `SECURITY_BAN_HOURS` | `24` | How long an automatic ban lasts |
| `SECURITY_LOGIN_HISTORY_DAYS` | `90` | Days to keep the login history |
| `SECURITY_STAFF_TWO_FACTOR` | `false` | Require two-factor authentication for staff |
| `SECURITY_NEW_DEVICE_EMAILS` | `true` | Email clients and contacts about portal logins from new devices |
| `CAPTCHA_PROVIDER` | (off) | `turnstile`, `hcaptcha` or `recaptcha`, with `CAPTCHA_SITE_KEY` and `CAPTCHA_SECRET_KEY` |

Late fees are added once per invoice and are not taxed. Each reminder is sent once per invoice, and a reminder skipped on a missed night is sent on the next run. The late fee, tax-inclusive, invoice PDF, order review, activity log, inactive ticket, business hours, guest ticket and language settings can also be changed on the admin **Settings** page, which also has the ticket spam filter. The email and spam log is cleared of entries older than 90 days every night, and so are login history older than the days set, IP bans that have ended and devices unused for a year. The security settings are on **Settings > Security**.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
