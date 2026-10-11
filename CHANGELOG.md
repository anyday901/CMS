# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Support tickets: clients open tickets in the portal under **Support**, choosing a department and priority, and reply or close them there. Staff work them on a new **Tickets** page, filtered by status, department and "assigned to me", with the most urgent and longest waiting first. Staff reply, add internal notes clients never see, and change the status, priority, department and assignee. Clients and staff can attach up to 5 files of 10 MB each (images, PDFs, text, office documents and zip). Files are kept on the private disk and always downloaded, never shown in the browser.
- Ticket departments under **Settings > Departments**, each with an optional email address used as the reply-to on ticket emails and for piped mail.
- Inbound email for tickets: `php artisan tickets:pipe` reads an email on standard input (for a mail server pipe), and `POST /webhooks/email` takes the same raw email over HTTP when `TICKETS_INBOUND_SECRET` is set. A reply with `[Ticket #N]` in the subject from that ticket's client is added to the ticket; an email from a known client to a department address opens a new one. Quoted text and unsafe attachments are dropped, and strangers, auto-replies and bulk mail are ignored.
- Staff who handle tickets are emailed about new tickets and client replies; when a ticket is assigned, only the assignee is.
- Client emails: new invoice, overdue reminder, payment receipt, password link, ticket received and ticket reply emails, each with a default subject and Markdown body that staff can change or turn off under **Email templates**, using placeholders like `{invoice_number}`.
- Email log: every email to a client is listed under **Emails** with its status and full text, and a client's page shows their recent emails. Emails are sent from the queue and retried; staff can send a failed email again. Password links are left out of the log.
- New invoice emails attach the invoice PDF (turn off on the **Settings** page or with `BILLING_ATTACH_INVOICE_PDF=false`).
- Overdue reminders: `billing:run` emails a reminder when an unpaid invoice is overdue by each number of days in `BILLING_REMINDER_DAYS` (default 1 and 7).
- Custom staff role: tick exactly which abilities a staff member has (staff, settings, clients, billing, tickets) instead of using a built-in role.
- Activity log retention: set how many days to keep entries on the **Settings** page or with `ACTIVITY_RETENTION_DAYS`, and `php artisan activity:prune` (run nightly by the scheduler) deletes older ones.
- The dashboard lists tickets waiting on staff.
- Canned replies: staff save common ticket answers under **Tickets > Canned replies** and insert one into a reply from the ticket page. Placeholders such as `{client_first_name}`, `{ticket_id}` and `{staff_name}` are filled in for that ticket.
- Service welcome email, sent when a service is activated (its first invoice is paid, or staff add it without an invoice). Each product can add its own text to it, such as how to log in, with the new **Welcome email text** field. Staff can edit or turn it off like the other templates.
- Log in as client: staff who manage clients can open the portal as a client from the client's page. A banner shows while they do, everything they do there is logged under their own name, and **Back to admin** (or logging out of the portal) returns them to the client's page still signed in as staff.
- Client contacts: extra people on a client's account, added by staff on the client's page or by the client under **Account**. Contacts can be copied on invoice emails, support emails or both, and a contact who gets support emails can open and reply to tickets by email. The email log shows who was copied.
- Configurable options on products: dropdowns, checkboxes and quantities, each choice with a price per billing cycle and a setup fee. Options are picked when a service is added, priced into its recurring amount and first invoice, listed on its invoices, and can be changed later by staff.
- Custom fields on clients and services (text, text area, dropdown or checkbox, optionally required or staff only), set under **Settings > Custom fields**. Service fields can apply to every product or one product. Clients fill in their own fields on the portal account page and see service fields on the service page.
- The webhook provisioning module now sends custom fields by name and the service's configurable options.

### Changed
- The roadmap in `TODO.md` and `docs/spec.md` now aims at full WHMCS feature parity after v1 (milestones 5 to 12).
- Support staff can now handle tickets, and billing staff can too. Everything else about the built-in roles is unchanged.
- Portal password emails use the editable "Password link" template and appear in the email log.
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
