# v1 Spec: Open Source Billing Platform (WHMCS replacement)

Status: draft for Corey's review. Assumptions are marked **(assumed)**.

## Goal

A free, self-hosted billing and client management app that can fully replace WHMCS for Corey's business (about 2,500 active clients) and then be published as open source.

## Stack

- **Laravel 13 (PHP 8.3+)**, MySQL/MariaDB, Redis for queues, Blade + Livewire for the UI. **(assumed, Corey has not objected)**
- Runs on any Linux server with sudo: Nginx, PHP-FPM, a cron entry for the scheduler, and a queue worker under systemd.
- Docker Compose setup for development and as an optional install route.
- License: **AGPL-3.0** **(assumed)**, so hosted forks must share changes. MIT is the alternative if wider commercial reuse matters more.

## In scope for v1

### Clients
- Client accounts with contacts, billing address, notes, status (active, inactive, closed).
- Client portal login with email and password, password reset, optional 2FA.
- Admin can log in as a client.

### Products and services
- Products with billing cycles (monthly, quarterly, semi-annual, annual, one-time), setup fees and configurable options.
- A service is one client's instance of a product, with next due date, status (pending, active, suspended, terminated, cancelled) and custom fields.

### Billing
- Recurring invoice generation a set number of days before the due date.
- Invoices with line items, tax rules, credits, partial payments, refunds.
- Overdue handling: reminders, late fees, auto-suspend and auto-terminate after configurable days.
- Credit balance and manual payments (cash, check, bank).
- PDF invoices.

### Payment gateways
A gateway plugin interface, with three gateways in v1:
- **PayPal**: one-time checkout and PayPal subscriptions for recurring billing.
- **Venmo**: offered through PayPal's checkout (US only). To be confirmed in the build whether saved Venmo payment methods allow automatic recurring charges.
- **Cash App Pay**: through Square's API. To be confirmed whether it allows saved, automatic recurring charges or only pay-per-invoice.

Where a gateway can't charge automatically, clients get the invoice by email and pay by link.

### Provisioning
Corey uses no control panel, so v1 provisioning is:
- **Manual fulfillment**: a new order or a cancellation creates an admin task ("set up", "suspend", "terminate") with a checklist.
- **Webhook module**: optionally fires an HTTP call on create, suspend, unsuspend and terminate, so it can be connected to anything later.
- A provisioning module interface so control panel modules (cPanel, Plesk, Proxmox and so on) can be added after v1.

### Support tickets
- Departments, priorities, statuses, staff assignment, internal notes, attachments.
- Clients open and reply to tickets in the portal or by email (inbound email piping).
- Canned replies.

### Email
- Templated emails for invoices, reminders, receipts, welcome, password reset, ticket updates.
- SMTP configuration, with an email log.

### Admin
- Staff accounts with roles and permissions.
- Dashboard: income this month, overdue invoices, open tickets, pending tasks.
- Activity log for audit.

### WHMCS importer
- Reads a WHMCS database (read-only connection or SQL dump).
- Imports clients, contacts, products, services, invoices, transactions, tickets and replies.
- Dry-run mode with a report of counts and mismatches.
- Client passwords: WHMCS hashes may not be reusable. Plan is to try to verify old hashes on first login and rehash; if that fails, clients get a reset link.

## Out of scope for v1
- Domain registration and registrar modules.
- Control panel modules.
- Affiliates, quotes, knowledge base, multi-currency, multi-language.
- Marketplace for third-party modules.

## Milestones
1. Spec agreed and repo set up.
2. Billing core: clients, products, services, invoices, recurring engine, client portal, PayPal.
3. Venmo and Cash App, provisioning (manual and webhook), tickets, email.
4. WHMCS importer, a side-by-side billing cycle on Corey's data, then switchover and public release.

## Open questions for Corey
- With no control panel, what does "provisioning" mean for you today? For example, do you set things up by hand, or does WHMCS call a script or API?
- Do clients pay automatically each cycle, or pay each invoice by hand?
- Name for the project and repo.
- AGPL-3.0 or MIT.
