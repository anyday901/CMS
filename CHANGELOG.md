# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Tax rules: a Tax page in the admin area for rates by country, or by country and state, tax-exempt clients and non-taxable products. New invoices use the most specific matching rule and keep that rate even if the rule changes later.
- Credit balance is applied to invoices: automatically when new invoices are created (`BILLING_APPLY_CREDIT`), from an "Apply credit" form in the admin area, and from a button on the client's invoice page. The portal home shows the client's credit.
- Refunds from the admin invoice page: full or partial, sent back through PayPal or Square, recorded as refunded outside the app, or moved to account credit. Fully refunded invoices are marked refunded. Refunds the gateway reports as pending are checked by `billing:run` and undone if they fail.
- Late fees: `billing:run` adds a one-time fixed or percentage fee to invoices unpaid a set number of days after the due date (`BILLING_LATE_FEE_*`). Off by default.
- Online payments on unpaid invoices in the client portal: PayPal and Venmo through PayPal Checkout, and Cash App Pay through Square. Payments are confirmed server side, matched to the invoice and recorded with the provider's fee. PayPal payments that clear later are recorded through a signed webhook at `/webhooks/paypal`. Declined PayPal captures are reported to the client as failed, and the admin invoice page shows each payment's gateway fee.
- Payment gateway plugin interface (`App\Payments\Gateway`) and `config/payments.php`.
- Transactions record how the client paid within a gateway, such as Venmo through PayPal.
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
- Admin and portal login and password pages no longer autofocus the first field.
- SonarCloud skips its PHP line-length and brace-style rules, since Laravel Pint already enforces code style.
- The frontend uses the system font instead of downloading Instrument Sans at build time, so builds work without internet access.
- `/` redirects to the client portal; staff use `/admin`. The default Laravel welcome page is removed.

### Fixed
- Admin client search box and product price fields now have labels, so screen readers announce them.

## [0.0.1] - 2026-10-09

### Added
- Laravel 13 project skeleton, AGPL-3.0-or-later license and CI test workflow.

[Unreleased]: https://github.com/anyday901/cms/compare/28bc321...HEAD
[0.0.1]: https://github.com/anyday901/cms/commit/28bc321
