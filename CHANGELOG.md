# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Client portal at `/portal`: client login, password setup and reset by email, a home page with active services and unpaid invoices, invoice and service pages limited to the client's own records, and account details and password change.
- Clients whose account is closed are signed out on their next portal request, even with an existing session or remember-me cookie.
- "Email portal password link" button on the admin client page, so clients created by staff can set their first password.
- Admin area at `/admin`: staff login, dashboard (income, unpaid and overdue invoices, active clients and services), client list with search, client create and edit, product and pricing management, adding services to clients with an optional first invoice and setup fee, service suspend, unsuspend and terminate, invoice list with status filters, invoice view, recording manual payments and cancelling invoices. Cancelling a new service's first invoice also cancels the pending service.
- `php artisan admin:create` command to create staff accounts.
- Amount fields reject malformed input such as `12,34.56` instead of guessing.
- Billing data model: clients, products with per-cycle pricing, services, invoices, invoice items and transactions. All money is stored in integer cents.
- Billing cycles: one time, monthly, quarterly, semi-annually, annually and biennially, with month-end safe date math.
- Recurring invoice generation a configurable number of days before services are due, grouped into one invoice per client and safe to run repeatedly or concurrently.
- Payment recording: duplicate gateway callbacks return the original transaction, a reference already used on another invoice is rejected, partial payments, marking invoices paid, advancing service due dates and activating pending services.
- Overdue handling: automatic suspension and termination after configurable days, and unsuspension once paid.
- Service and invoice events (`ServiceActivated`, `ServiceSuspended`, `ServiceUnsuspended`, `ServiceTerminated`, `InvoicePaid`) that fire only after the database commit.
- `billing:run` command, scheduled daily.
- Billing settings in `config/billing.php`.
- Minimum Node version declared in `package.json`.
- Project docs: v1 spec, README (with required PHP extensions and Node version), TODO and this changelog.

### Changed
- The frontend uses the system font instead of downloading Instrument Sans at build time, so builds work without internet access.
- `/` redirects to the client portal; staff use `/admin`. The default Laravel welcome page is removed.

## [0.0.1] - 2026-10-09

### Added
- Laravel 13 project skeleton, AGPL-3.0-or-later license and CI test workflow.

[Unreleased]: https://github.com/anyday901/cms/compare/28bc321...HEAD
[0.0.1]: https://github.com/anyday901/cms/commit/28bc321
