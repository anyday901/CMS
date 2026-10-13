# TODO

The goal is a direct open source rival to WHMCS. Milestones 2 to 4 get Corey's business off WHMCS (v1); milestones 5 to 12 close the remaining gap with WHMCS, in rough order. See [docs/spec.md](docs/spec.md) for details. Move items to [CHANGELOG.md](CHANGELOG.md) when they ship.

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
- [x] Own visual theme (Harbor) for the admin area and client portal
- [ ] Use the Harbor colors on PDF invoices
- [ ] Client 2FA
- [x] Client sign-up and ordering from the portal
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
- [x] Disputes for PayPal payments (PayPal webhook)
- [x] Notify staff by email when a provisioning action fails or a payment is disputed
- [x] Manual fulfillment tasks
- [x] Webhook provisioning module
- [ ] Corey's custom provisioning module
- [x] Canned replies for tickets
- [x] Welcome email when a service is activated
- [x] Admin can log in as a client
- [x] Client contacts (extra people on an account)
- [x] Configurable options on products (choices that change the price)
- [x] Custom fields on clients and services
- [x] Support tickets with departments, priorities, assignment and attachments
- [x] Inbound email piping for tickets
- [x] Email templates and email log
- [x] Attach the PDF to invoice emails
- [x] Custom staff permissions beyond the three built-in roles
- [x] Activity log retention setting

## Milestone 4: Migration and release
- [x] WHMCS importer with dry-run report
- [x] Rehash WHMCS client passwords on first login
- [x] Side-by-side billing comparison tool (`whmcs:compare`)
- [x] Step-by-step WHMCS import walkthrough in the admin area (Settings > Import from WHMCS)
- [ ] Side-by-side billing cycle on production data (Corey runs it; see docs/install.md)
- [x] Install guide (Nginx, PHP-FPM, cron, queue worker)
- [x] Docker Compose setup
- [x] Release checklist and steps (docs/release.md)
- [ ] Public v1.0.0 release (waits on the switchover and Corey's go-ahead)
- [x] Clear SonarCloud reliability findings on main (unlabelled admin inputs)

## Milestone 5: Ordering and cart
- [x] Public product catalogue with product groups
- [x] Shopping cart and checkout with account sign-up
- [x] Product add-ons
- [x] Promotion codes (percent or fixed, recurring or first payment, expiry and usage limits)
- [x] Upgrades and downgrades with prorated charges or credit
- [x] Prorated billing to a fixed day of the month
- [x] Free trials
- [x] Stock control on products
- [x] Pending orders queue: accept, cancel or mark fraud
- [ ] Fraud checks on new orders (pluggable, e.g. MaxMind)
- [x] Client cancellation requests (immediate or end of period)
- [x] Order and invoice email templates for new orders
- [x] Staff upgrades and downgrades from the admin service page, and a billing cycle change with the move
- [x] Orders list in the admin area
- [x] Compact, searchable add-on and upgrade pickers on the product form, with select all and clear

## Milestone 6: Billing parity
- [ ] Multiple currencies with exchange rates and per-currency prices
- [ ] Quotes that convert to invoices once accepted
- [ ] Credit notes
- [ ] Add funds (prepay into credit) and mass pay of several invoices at once
- [ ] Saved payment methods and automatic charging on the due date
- [ ] Stripe gateway (cards, wallets, ACH and SEPA)
- [ ] Bank transfer and cheque gateways with payment instructions
- [ ] Direct debit gateway (e.g. GoCardless)
- [ ] Billable items: one-off or recurring charges and time tracking added to the next invoice
- [ ] Merge unpaid invoices
- [ ] Sequential invoice numbers for paid invoices only, with a proforma number before payment
- [ ] Compound and multi-level tax, EU VAT number validation and reverse charge
- [ ] Account statements for a date range
- [ ] Gateway log of every callback and webhook
- [ ] Client groups with discounts and their own settings

## Milestone 7: Domains
- [ ] Domain registration, transfer and renewal orders
- [ ] TLD pricing per term and currency
- [ ] Domain availability and WHOIS lookup
- [ ] Registrar module interface
- [ ] First registrar modules (e.g. Enom, ResellerClub, Namecheap, OpenSRS)
- [ ] Domain sync of expiry dates and statuses
- [ ] Nameserver, DNS, contact and ID protection management in the portal
- [ ] Domain renewal reminders and auto-renew

## Milestone 8: Provisioning modules
- [ ] Servers and server groups, with fill-up and least-used assignment
- [ ] cPanel / WHM module
- [ ] Plesk module
- [ ] DirectAdmin module
- [ ] Proxmox module
- [ ] Client single sign-on to the control panel from the portal
- [ ] Usage statistics and usage-based billing (disk, bandwidth, overages)
- [ ] Software licensing module
- [ ] Server status monitoring

## Milestone 9: Support parity
- [ ] Knowledge base with categories and search
- [ ] Announcements
- [ ] Network status (planned and current issues)
- [ ] Downloads area, optionally limited to clients with a product
- [ ] Custom ticket fields per department
- [ ] Ticket escalation rules and SLAs
- [ ] Auto-close inactive answered tickets
- [ ] Merge and split tickets, and ticket tags
- [ ] Ticket feedback ratings
- [ ] Tickets from non-clients (guest tickets by email or form)
- [ ] Spam filtering for inbound ticket email

## Milestone 10: Clients, security and staff
- [ ] Two-factor authentication (TOTP) for clients and staff
- [ ] Contacts with their own logins and permissions (billing, support, orders)
- [ ] Email address verification
- [ ] Login history, failed login lockout and IP bans
- [ ] CAPTCHA on public forms
- [ ] GDPR tools: export a client's data, anonymise a closed account
- [ ] Client notes and admin to-do list
- [x] Advanced search on the admin clients list, hiding closed clients by default
- [ ] Client groups (and a client group filter on the clients search)
- [ ] Global search across clients, services, invoices, domains and tickets
- [ ] Merge two client accounts
- [ ] Marketing email opt-in and opt-out

## Milestone 11: Reports, automation and integrations
- [ ] Reports: income, sales by product, invoice aging, tax, client growth, ticket stats, with CSV export
- [ ] Automation settings page (all billing run days in one place) and a cron status page
- [ ] Mass mail to filtered clients
- [ ] Staff notifications to Slack, Discord or Teams
- [ ] SMS notifications (pluggable provider)
- [ ] REST API with keys and permissions
- [ ] Outgoing webhooks and an event hook system for plugins
- [ ] Add-on module system (install, configure, enable from the admin area)
- [ ] Affiliates: referral links, commissions and payouts
- [ ] Project management add-on (projects, tasks, time linked to invoices)

## Milestone 12: Platform and community
- [ ] Translations (multi-language portal and emails)
- [ ] Portal themes and template overrides
- [ ] White-label settings (logo, colors, company details in the admin area)
- [ ] SMTP and other mail settings in the admin area
- [ ] Web installer and one-click updater
- [ ] Maintenance mode and system health page
- [ ] Database backups from the admin area
- [ ] Documentation site, module developer guide and demo instance
- [ ] Module and theme marketplace

## Open questions
- AGPL-3.0 or MIT license (AGPL assumed for now).
