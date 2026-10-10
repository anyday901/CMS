# CMS

A free, self-hosted billing, client portal and support platform. It is an open source alternative to WHMCS.

> Status: early development. Not ready for production use yet.

## What it does (v1 goals)

- Client accounts and a client portal
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

## Client portal

Clients log in at `/portal` to see their services and invoices and update their details. Clients you add in the admin area start without a password: use **Email portal password link** on their page, or have them use **Forgot or never set your password?** on the login page. Set the `MAIL_*` settings in `.env` so these emails are delivered; with the default `MAIL_MAILER=log` they are written to `storage/logs/laravel.log` instead.

## Online payments

Clients pay each invoice from the portal. A payment method appears on unpaid invoices once its settings are in `.env`. Leave them blank to hide it.

### PayPal and Venmo

1. In the [PayPal developer dashboard](https://developer.paypal.com/dashboard/applications), create a REST app and copy its client ID and secret.
2. Add a webhook to that app pointing at `https://your-domain/webhooks/paypal`, subscribed to **Payment capture completed**, and copy its webhook ID.
3. Set these in `.env`:

```dotenv
PAYPAL_MODE=sandbox        # "live" for real payments
PAYPAL_CLIENT_ID=...
PAYPAL_SECRET=...
PAYPAL_WEBHOOK_ID=...
PAYPAL_VENMO=true          # show the Venmo button to US clients
```

Venmo payments go through PayPal and show as "Venmo" on the invoice.

### Cash App Pay

1. In the [Square developer dashboard](https://developer.squareup.com/apps), create an application and copy its application ID and access token, plus the location ID of the location that should receive payments.
2. Set these in `.env`:

```dotenv
SQUARE_ENVIRONMENT=sandbox # "production" for real payments
SQUARE_APPLICATION_ID=...
SQUARE_ACCESS_TOKEN=...
SQUARE_LOCATION_ID=...
```

Test with sandbox credentials first; both providers have sandbox accounts for fake payments.

## Admin area

The admin area at `/admin` covers the dashboard, clients, products and pricing, services (add, suspend, unsuspend, terminate), invoices (create, edit, view, download as PDF, record payments, apply credit, refund, cancel), tax rules, staff and the activity log.

### Staff roles

Each staff account has one role:

| Role | Can do |
| --- | --- |
| Admin | Everything, including staff, products, tax rules and the activity log |
| Billing | Create and edit clients, services and invoices; record payments, apply credit and refund |
| Support | Look at clients, services and invoices, and download invoice PDFs, without changing anything |

Admins add and edit staff under **Staff**. You can't delete your own account or remove your own admin role, so there is always at least one admin. From the command line, `php artisan admin:create --role=billing` creates a staff account with a role (the default is `admin`). Staff accounts that existed before roles were added are admins.

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

Admins can see who did what under **Activity**: staff and client logins, changes to clients, products, tax rules and staff, invoices created, edited, published and cancelled, payments, refunds, late fees and service status changes. Each entry records the person (or "System" for the nightly billing run and payment webhooks) and their IP address. A client's page shows their 20 most recent entries.

### Tax

Add tax rules under **Tax**. Each rule has a rate and optionally a country, or a country and state. A new invoice uses the most specific rule matching the client's address, and taxes only products marked taxable. Mark a client tax exempt on their edit page. Invoices keep the rate they were created with, so changing a rule doesn't change existing invoices.

### Credit and refunds

Overpayments and refunds to credit go to the client's credit balance. New invoices are paid from credit automatically (turn this off with `BILLING_APPLY_CREDIT=false`), and staff or the client can apply credit to an unpaid invoice by hand. Credit payments don't count as income on the dashboard.

To refund a payment, open its invoice and use **Refund** under the payment. PayPal, Venmo and Cash App payments can be sent back through the gateway (if the gateway reports the refund as pending, the nightly billing run checks it and undoes the record if it later fails); any payment can be recorded as refunded outside the app or moved to account credit. A fully refunded invoice is marked refunded. Services are left as they are, so suspend or terminate them yourself if needed.

## Scheduled jobs

Add the Laravel scheduler to cron so billing runs daily:

```cron
* * * * * cd /path/to/cms && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan billing:run` generates renewal invoices, adds late fees and suspends or terminates overdue services. Its settings are in `config/billing.php` and can be set from `.env`:

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

Late fees are added once per invoice and are not taxed.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
