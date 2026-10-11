# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- PayPal disputes: the PayPal webhook now takes the customer dispute events. Disputed PayPal and Venmo payments are flagged on the invoice, and a dispute resolved in the buyer's favour or accepted is recorded as a refund of the disputed amount.
- Staff emails when a provisioning action fails, a manual task opens, or a payment is disputed or its dispute changes. Admin and billing staff get provisioning and task emails; staff who manage billing get dispute emails.
- Manual fulfillment module: each provisioning action opens a task with the product's checklist. A new **Tasks** page lists open and finished tasks, staff tick steps, add notes and mark tasks done, and open tasks show on the dashboard and the service page.
- Webhook provisioning module: sends create, suspend, unsuspend and terminate to a URL as signed JSON, and saves any `data` the receiver sends back on the service.
- Provisioning module settings can be text areas or web addresses (`type` in `configFields()`).
- Harbor theme for the admin area and client portal: sea-green and warm-sun colors, Bricolage Grotesque headings over Figtree text, rounded cards, pill buttons and navigation. Fonts are bundled with the app, so pages make no requests to font services. Colors, fonts and corner sizes are set in `resources/css/app.css`.
- Square webhook at `/webhooks/square` for Cash App Pay: refunds made in the Square dashboard are recorded on the invoice, pending refunds are settled as soon as Square reports them, and disputes are shown on the payment. A lost or accepted dispute is recorded as a refund. Requests are checked against `SQUARE_WEBHOOK_SIGNATURE_KEY`.
- Credit history: every change to a client's credit balance (overpayments, refunds to credit, credit used on invoices and manual changes) is listed with the balance after it, on the admin client page and the client's portal home.
- Staff with the billing or admin role can add or remove credit by hand, with a reason. The balance can't go below zero.
- Settings page for admins to change late fees and turn on tax-inclusive pricing without editing `.env`.
- Tax-inclusive pricing (`BILLING_TAX_INCLUSIVE`): invoice totals equal the sum of their lines and show the tax they contain. Invoices keep the setting they were created with.
- Provisioning module interface (`App\Provisioning\ProvisioningModule`): products can use a module registered in `config/provisioning.php`, whose create, suspend, unsuspend and terminate actions run on the queue when a service's status changes. The service page shows the last result and lets staff run an action again.
- PDF invoices: staff download any invoice and clients download their own from the invoice page. Company name, address, email and tax id on the PDF come from `BILLING_COMPANY_*` settings.
- Staff roles: admin, billing and support. Billing staff manage clients, services and invoices; support staff can only look. Admins manage staff accounts on a new Staff page, can't delete or demote themselves, and there is always at least one admin. `admin:create` takes `--role`.
- Manual invoices: staff create invoices with any lines (negative lines as discounts) from a client's page, save them as drafts hidden from the client, publish them, and edit draft or unpaid invoices. Edits can't bring the total down to or below what is already paid, or remove service renewal lines. Drafts can be cancelled.
- Activity log: logins, client, product, tax rule and staff changes, invoice, payment, refund, late fee and service events are recorded with who did them and their IP address. Admins browse and search it on a new Activity page, and each client's page shows their recent activity.
- Draft filter on the admin invoice list.
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
- Existing staff accounts become admins. Admin pages hide buttons and menu links the signed-in role can't use.
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
