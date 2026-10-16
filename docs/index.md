# CMS

CMS is a free, self-hosted billing, client portal and support platform: an open source alternative to WHMCS. It runs on your own Linux server (Laravel 13 on PHP 8.3 or newer, with MySQL or MariaDB), and it is licensed under the AGPL-3.0-or-later.

!!! warning "Early development"
    CMS is not ready for production use yet. The first public release, v1.0.0, follows the switchover of its first business from WHMCS (see [Releasing](release.md)).

## What it does

**Billing**

- Products and services with recurring billing, setup fees, configurable options, free trials, billing on a fixed day of the month and stock control
- Invoices with tax rules, credits, partial payments, refunds, late fees, reminders, and suspension and termination when overdue
- PDF invoices, a credit balance, and manual payments (cash, check, bank)
- Clients pay from the portal with PayPal, Venmo (through PayPal) or Cash App Pay (through Square)

**Store and ordering**

- A public store, cart and checkout with sign-up
- Product add-ons, promotion codes, upgrades and downgrades, and cancellation requests
- Every checkout saved as an order, with a pending orders queue to accept, cancel or mark as fraud

**Provisioning**

- Manual fulfillment tasks with checklists, or a webhook to any system of yours
- cPanel / WHM, Plesk, DirectAdmin and Proxmox VE servers, grouped and filled least used first or one at a time
- Moving accounts between servers, disk and bandwidth usage with overage billing, and server status monitoring
- Software licensing: license keys your own software checks, locked to where they are installed

**Support**

- Tickets with departments, email piping, a spam filter, guest tickets, custom fields, SLAs, escalation rules, tags, merging and splitting
- A knowledge base, announcements, a network status page and a downloads area

**Clients and security**

- A client portal, contacts with their own logins and permissions, client groups and staff notes
- Two-factor authentication for clients and staff, login history, failed login lockout, IP bans, CAPTCHA, and GDPR export and anonymising

**Admin and integrations**

- Staff roles and permissions, a search box for everything, reports with CSV export, an activity log and a cron status page
- A web installer, one-click updates, database backups, maintenance mode and a system health page
- Themes, template overrides and white-label settings, and the portal and client emails in several languages
- Mail settings in the admin area, and a marketplace of add-ons and themes
- A REST API, signed outgoing webhooks, chat notifications to Slack, Discord or Teams, and SMS
- Add-ons, an event hook system for plugins, and affiliates
- An importer for existing WHMCS installs

## Where to start

| | |
|---|---|
| **Install it** | [Installing CMS on a server](install.md): Ubuntu with Nginx, PHP-FPM, MariaDB, cron and a queue worker, or Docker Compose |
| **Upgrade it** | One click under **Settings > Updates**, or [by hand](install.md#updating) for a git checkout |
| **Try it** | [Run a demo instance](demo.md) with sample data that resets itself |
| **Move from WHMCS** | [Moving from WHMCS](install.md#moving-from-whmcs): import, compare a billing cycle side by side, then switch |
| **Run it** | The admin guide: [appearance and white-label](admin/appearance.md), [languages](admin/languages.md), [mail](admin/mail.md), [health, maintenance, backups and updates](admin/system.md) and the [marketplace](admin/marketplace.md). The rest of the admin area is described in the [README](https://github.com/anyday901/CMS/blob/main/README.md#admin-area) |
| **Connect to it** | The [REST API](api.md), [webhooks and notifications](webhooks.md), and [software licensing](licensing.md) checks |
| **Extend it** | The [developer guide](developers/index.md): add-ons, payment gateways, provisioning modules, SMS providers, event hooks, themes, translations and marketplace packages |

## The project

- The [product spec](spec.md) says what v1 covers and what comes after it.
- [Releasing](release.md) is the checklist for v1.0.0 and the steps to cut a release.
- Progress is tracked in [TODO.md](https://github.com/anyday901/CMS/blob/main/TODO.md), and changes in [CHANGELOG.md](https://github.com/anyday901/CMS/blob/main/CHANGELOG.md).
- The code is on [GitHub](https://github.com/anyday901/CMS). Issues and pull requests are welcome.
