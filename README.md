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

Then open http://localhost:8000/admin and log in with the staff account you just created. To reach it from another machine, run `php artisan serve --host=0.0.0.0` and allow port 8000 through your firewall.

Run the tests with `php artisan test`.

## Admin area

The admin area at `/admin` covers the dashboard, clients, products and pricing, services (add, suspend, unsuspend, terminate) and invoices (view, record payments, cancel). Create staff accounts with `php artisan admin:create`.

## Scheduled jobs

Add the Laravel scheduler to cron so billing runs daily:

```cron
* * * * * cd /path/to/cms && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan billing:run` generates renewal invoices and suspends or terminates overdue services. Its settings are in `config/billing.php` and can be set from `.env`:

| Setting | Default | Meaning |
| --- | --- | --- |
| `BILLING_CURRENCY` | `USD` | Currency for new clients |
| `BILLING_INVOICE_DAYS_BEFORE_DUE` | `7` | Days before the due date to create the renewal invoice |
| `BILLING_SUSPEND_AFTER_DAYS` | `3` | Days overdue before suspending |
| `BILLING_TERMINATE_AFTER_DAYS` | `30` | Days overdue before terminating |

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
