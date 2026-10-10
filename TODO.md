# TODO

Work toward v1, in rough order. See [docs/spec.md](docs/spec.md) for details. Move items to [CHANGELOG.md](CHANGELOG.md) when they ship.

## Milestone 2: Billing core
- [x] Data model for clients, products, prices, services, invoices, items and transactions
- [x] Recurring invoice generation
- [x] Payment recording and service due date advancement
- [x] Overdue suspension and termination
- [x] Tax rules
- [x] Client credit balance applied to invoices
- [x] Refunds
- [x] Late fees
- [x] PDF invoices
- [x] Admin area: staff login, dashboard, clients, products, services, invoices
- [x] Record manual payments and cancel invoices
- [x] Staff roles and permissions
- [x] Manual invoice creation and editing
- [x] Activity log
- [x] Client portal: login, password setup and reset, invoices, services, account
- [ ] Client 2FA
- [ ] Client sign-up and ordering from the portal
- [x] PayPal gateway (one-time checkout per invoice)
- [x] Payment gateway plugin interface
- [ ] Test PayPal, Venmo and Cash App with real sandbox accounts

## Milestone 3: Payments, provisioning, support
- [x] Venmo through PayPal checkout
- [x] Cash App Pay through Square
- [x] Square webhook for Cash App refunds and disputes
- [x] Refunds through the gateways from the admin area
- [x] Admin adjustment of client credit balances, with a credit history
- [x] Late fee settings and tax-inclusive pricing in the admin area
- [x] Provisioning module interface
- [ ] Disputes for PayPal payments (PayPal webhook)
- [ ] Notify staff by email when a provisioning action fails or a payment is disputed
- [ ] Manual fulfillment tasks
- [ ] Webhook provisioning module
- [ ] Corey's custom provisioning module
- [ ] Support tickets with departments, priorities, assignment and attachments
- [ ] Inbound email piping for tickets
- [ ] Email templates and email log
- [ ] Attach the PDF to invoice emails
- [ ] Custom staff permissions beyond the three built-in roles
- [ ] Activity log retention setting

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
