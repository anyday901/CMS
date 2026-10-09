# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Billing data model: clients, products with per-cycle pricing, services, invoices, invoice items and transactions. All money is stored in integer cents.
- Billing cycles: one time, monthly, quarterly, semi-annually, annually and biennially, with month-end safe date math.
- Recurring invoice generation a configurable number of days before services are due, grouped into one invoice per client and safe to run repeatedly.
- Payment recording: partial payments, marking invoices paid, advancing service due dates and activating pending services.
- Overdue handling: automatic suspension and termination after configurable days, and unsuspension once paid.
- `billing:run` command, scheduled daily.
- Billing settings in `config/billing.php`.
- Project docs: v1 spec, README, TODO and this changelog.

## [0.0.1] - 2026-10-09

### Added
- Laravel 13 project skeleton, AGPL-3.0-or-later license and CI test workflow.

[Unreleased]: https://github.com/anyday901/cms/compare/v0.0.1...HEAD
[0.0.1]: https://github.com/anyday901/cms/releases/tag/v0.0.1
