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

The full v1 spec is in [docs/spec.md](docs/spec.md).

## Requirements

- PHP 8.3 or newer, Composer, Node 20 or newer
- MySQL 8 / MariaDB 10.6 or newer (SQLite works for local development)

## Local development

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install && npm run build
php artisan serve
```

Run the tests with `php artisan test`.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
