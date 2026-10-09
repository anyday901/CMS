# TODO

Work toward v1, in rough order. See [docs/spec.md](docs/spec.md) for details. Move items to [CHANGELOG.md](CHANGELOG.md) when they ship.

## Milestone 2: Billing core
- [x] Data model for clients, products, prices, services, invoices, items and transactions
- [x] Recurring invoice generation
- [x] Payment recording and service due date advancement
- [x] Overdue suspension and termination
- [ ] Tax rules
- [ ] Client credit balance applied to invoices
- [ ] Refunds
- [ ] Late fees
- [ ] PDF invoices
- [x] Admin area: staff login, dashboard, clients, products, services, invoices
- [x] Record manual payments and cancel invoices
- [ ] Staff roles and permissions
- [ ] Manual invoice creation and editing
- [ ] Activity log
- [ ] Client portal: login, password reset, 2FA, invoices, services
- [ ] PayPal gateway (one-time checkout per invoice)
- [ ] Payment gateway plugin interface

## Milestone 3: Payments, provisioning, support
- [ ] Venmo through PayPal checkout
- [ ] Cash App Pay through Square
- [ ] Provisioning module interface
- [ ] Manual fulfillment tasks
- [ ] Webhook provisioning module
- [ ] Corey's custom provisioning module
- [ ] Support tickets with departments, priorities, assignment and attachments
- [ ] Inbound email piping for tickets
- [ ] Email templates and email log

## Milestone 4: Migration and release
- [ ] WHMCS importer with dry-run report
- [ ] Rehash WHMCS client passwords on first login
- [ ] Side-by-side billing cycle on production data
- [ ] Install guide (Nginx, PHP-FPM, cron, queue worker)
- [ ] Docker Compose setup
- [ ] Public v1.0.0 release
- [x] Clear SonarCloud reliability findings on main (unlabelled admin inputs)

## Open questions
- AGPL-3.0 or MIT license (AGPL assumed for now).
