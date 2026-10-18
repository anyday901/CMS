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
- [x] Use the Harbor colors on PDF invoices
- [x] Client 2FA
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
- [x] Import the emails WHMCS sent to clients, and bring them over into an install already imported (`whmcs:import --emails`)
- [x] Keep every service's WHMCS username and password, and bring them over into an install already imported (`whmcs:import --logins`)
- [ ] Side-by-side billing cycle on production data (Corey runs it; see docs/install.md)
- [x] Install guide (Nginx, PHP-FPM, cron, queue worker)
- [x] Docker Compose setup
- [x] Release checklist and steps (docs/release.md)
- [ ] Public v1.0.0 release (waits on the switchover and Corey's go-ahead)
- [x] Clear SonarCloud reliability findings on main (unlabelled admin inputs)
- [x] Clear SonarCloud's upload size alert on downloads (php:S5693)
- [x] Clear SonarCloud's code smells on the platform features (dedicated exceptions, constants, smaller methods)

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
- [x] Terms of service checkbox at checkout
- [x] Client cancellation requests (immediate or end of period)
- [x] Order and invoice email templates for new orders
- [x] Staff upgrades and downgrades from the admin service page, and a billing cycle change with the move
- [x] Orders list in the admin area
- [x] Compact, searchable add-on and upgrade pickers on the product form, with select all and clear

## Milestone 6: Billing parity
- [x] Multiple currencies with exchange rates and per-currency prices
- [x] Quotes that convert to invoices once accepted
- [x] Credit notes
- [x] Add funds (prepay into credit) and mass pay of several invoices at once
- [x] Saved payment methods and automatic charging on the due date
- [x] Stripe gateway (cards and the wallets Stripe's Payment Element offers)
- [ ] Stripe ACH and SEPA debits, and saving them for automatic charging
- [ ] Try Stripe, saved cards and automatic charging with a Stripe test account (built from Stripe's API docs and tested with mocked replies; the card form hasn't been run in a browser)
- [ ] Import WHMCS currencies and exchange rates
- [x] Bank transfer and cheque (mail-in) gateways with payment instructions
- [ ] Direct debit gateway (e.g. GoCardless)
- [x] Billable items: one-off or recurring charges and time tracking added to the next invoice
- [x] Merge unpaid invoices
- [x] Sequential invoice numbers for paid invoices only, with a proforma number before payment
- [x] Compound and multi-level tax, EU VAT number validation and reverse charge
- [x] Client tax ID / VAT number on the client profile, invoices and quotes
- [ ] Import WHMCS's level 2 tax rules, compound setting and client tax IDs
- [ ] Re-check unverified EU VAT numbers with VIES automatically
- [x] Account statements for a date range
- [x] Gateway log of every callback and webhook
- [x] Client groups with discounts and their own settings
- [x] Credit limits: let a client run up unpaid invoices to a limit before overdue suspension

## Milestone 7: Domains
- [x] Domain registration, transfer and renewal orders
- [x] TLD pricing per term and currency
- [x] Domain availability and WHOIS lookup
- [x] Registrar module interface
- [x] First registrar modules (e.g. Enom, ResellerClub, Namecheap, OpenSRS)
- [x] Domain sync of expiry dates and statuses
- [x] Nameserver, DNS, contact and ID protection management in the portal
- [x] Domain renewal reminders and auto-renew
- [ ] Try the Enom, Namecheap, OpenSRS and ResellerClub modules against live (or test environment) reseller accounts
- [ ] Import domains, TLD pricing and registrar settings from WHMCS
- [ ] Domains in the REST API, webhooks and reports
- [ ] Translate the new domain pages in the portal and store (their emails are translated)

## Milestone 8: Provisioning modules
- [x] Servers and server groups, with fill-up and least-used assignment
- [x] cPanel / WHM module
- [x] Plesk module
- [x] DirectAdmin module
- [x] Proxmox module
- [ ] Try the cPanel, Plesk, DirectAdmin and Proxmox modules against live servers (built from the vendors' API docs and tested with mocked replies)
- [x] Import servers, server groups and service logins from WHMCS
- [x] Clients change their control panel password from the portal
- [x] Move an account to another server from the service page
- [x] Upgrades and downgrades between products of different server modules (e.g. cPanel to Plesk), as a migration of the account
- [x] Proxmox LXC containers, and reinstalling a VM from its template
- [x] Client single sign-on to the control panel from the portal
- [x] Copy account data between servers automatically when moving (cPanel transfers, Proxmox migration), instead of by hand
- [ ] Try WHMCS password decryption, single sign-on and Proxmox containers against a real WHMCS database and live servers
- [x] Usage statistics and usage-based billing (disk, bandwidth, overages)
- [x] Software licensing module
- [x] Server status monitoring
- [ ] Try the cPanel transfer, Proxmox migration (in a cluster and remote), usage readings and server load against live servers (built from the API docs and tested with mocked replies)
- [ ] Automatic copies for Plesk (Plesk Migrator) and DirectAdmin moves, and for moves between modules
- [ ] Import WHMCS licensing add-on licenses
- [x] Client-facing server status page (with Network status in milestone 9)
- [x] Upload WHMCS server modules and run them through a compatibility layer (Settings > WHMCS modules)
- [x] Convert an uploaded WHMCS server module into a CMS module class (mechanical: the logic inside its functions is copied, not rewritten)
- [ ] Try uploaded WHMCS server modules against real modules and live servers (tested with a sample module written like WHMCS's)
- [ ] Run uploaded WHMCS registrar modules (once the registrar module interface is in), payment gateways and add-on modules
- [ ] Custom buttons and client area output of uploaded WHMCS modules

## Milestone 9: Support parity
- [x] Knowledge base with categories and search
- [x] Announcements
- [x] Network status (planned and current issues)
- [x] Downloads area, optionally limited to clients with a product
- [x] Custom ticket fields per department
- [x] Ticket escalation rules and SLAs
- [x] Import WHMCS knowledge base articles, announcements, network issues, downloads, ticket custom fields and escalation rules
- [x] Business hours for SLAs
- [x] Suggest knowledge base articles as a client types a new ticket
- [x] Auto-close inactive answered tickets
- [x] Merge and split tickets, and ticket tags
- [x] Ticket feedback ratings
- [x] Tickets from non-clients (guest tickets by email or form)
- [x] Spam filtering for inbound ticket email
- [x] Import WHMCS ticket feedback ratings and staff (admins), so imported escalation rules can be matched to everyone
- [x] Translations of knowledge base articles and announcements (the WHMCS import brings the main language only)
- [x] Translations of knowledge base category names and descriptions (the WHMCS import brings them over)
- [x] Map WHMCS admin role permissions to custom staff permissions (the closest built-in role by name only when WHMCS has no role permissions)

## Milestone 10: Clients, security and staff
- [x] Two-factor authentication (TOTP) for clients and staff, with recovery codes and an option to require it for staff
- [x] Contacts with their own logins and permissions (billing, orders, services, support), brought over from WHMCS sub-accounts and users
- [x] Email address verification
- [x] Login history, failed login lockout and IP bans (automatic and by hand)
- [x] CAPTCHA on public forms (Cloudflare Turnstile, hCaptcha, reCAPTCHA v2)
- [x] GDPR tools: export a client's data, anonymise a closed account
- [x] Contacts who can sign in to several client accounts with one login (WHMCS 8 users)
- [x] Remember-me for contacts
- [x] Email clients when there's a login from a new device
- [x] Client notes and admin to-do list
- [x] Advanced search on the admin clients list, hiding closed clients by default
- [x] Client groups (and a client group filter on the clients search)
- [x] Global search across clients, services, invoices, domains and tickets 
- [x] Merge two client accounts
- [x] Marketing email opt-in and opt-out
- [x] Client page in sections with a side menu and totals across the top, like WHMCS's client tabs
- [x] Admin service page inside the client page, with a service picker, WHMCS's two-column layout and in-place editing of server, login, dates, amount and cycle
- [x] Admin dashboard laid out like WHMCS's: shortcuts and system information on the side, four counts, the orders and income chart, billing, client activity, automation overview and activity
- [ ] Let staff hide, move and collapse dashboard panels, like WHMCS's widgets
- [ ] An account holder reaching other accounts with their own login too (WHMCS 8 users who own one client and are users of another)

## Milestone 11: Reports, automation and integrations
- [x] Reports area in the admin (Reports menu, each report with date range, currency and CSV export, behind a `view-reports` permission)
  - [x] Income: annual income, monthly transactions, daily performance, income forecast from active services
  - [x] Sales: income by product, new orders and new clients by month, promotion usage, top clients by income
  - [x] Invoices: aging (current, 30, 60, 90+ days), credits given and used, client statements
  - [x] Tax: tax collected by rate and country (sales tax liability, EU VAT)
  - [x] Clients and services: clients by country and group, client growth and churn, retention time, suspensions and cancellations by reason, server revenue
  - [x] Support: tickets opened and closed, first response and resolution times, replies by staff, ratings and feedback comments, tags
- [x] WHMCS import brings over the history reports read, so they cover the years before the switch: orders, promotions and their usage, the credit log, cancellation requests and the activity log (invoices, payments, services and tickets already come over)
- [x] The WHMCS import wizard explains that custom WHMCS reports (PHP files in `modules/reports`) can't be copied, since the importer only reads the database, and points to the closest built-in reports
- [x] Automation settings page (all billing run days in one place) and a cron status page
- [x] Mass mail to filtered clients (marketing ones only to clients who agreed, with their unsubscribe link and a List-Unsubscribe header)
- [x] Staff notifications to Slack, Discord or Teams
- [x] SMS notifications (pluggable provider)
- [x] REST API with keys and permissions
- [x] Outgoing webhooks and an event hook system for plugins
- [x] Add-on module system (install, configure, enable from the admin area)
- [x] Affiliates: referral links, commissions and payouts
- [x] Project management add-on (projects, tasks, time linked to invoices)
- [ ] Try the Twilio and Vonage SMS providers and the Slack, Discord and Teams channels with real accounts (built from their API docs and tested with mocked replies)
- [ ] Ticket attachments and setting a client's currency through the REST API
- [ ] Check the WHMCS affiliate import against a real WHMCS database (table and setting names written from WHMCS's schema without a live copy), and import per-product commissions

## Milestone 12: Platform and community
- [x] Translations (multi-language portal and emails)
- [x] Portal themes and template overrides
- [x] White-label settings (logo, colors, company details in the admin area)
- [x] SMTP and other mail settings in the admin area
- [x] Web installer and one-click updater
- [x] Maintenance mode and system health page
- [x] Database backups from the admin area
- [x] Log viewer (Settings > Logs, with level filter, search and download) and a PHP info page
- [x] Documentation site, module developer guide and demo instance
- [x] Module and theme marketplace
- [ ] Publish the docs site and marketplace registry (turn on GitHub Pages and set DOCS_DEPLOY=true; see docs/release.md)
- [ ] Host a public demo instance (DEMO_MODE=true; see docs/demo.md)
- [ ] Try the one-click updater against a real tagged release
- [ ] Translate the remaining English-only portal text: cart and upgrade errors, promotion and affiliate summaries, tax labels, usage and duration labels, CAPTCHA and rating widgets, and Laravel's validation and pagination messages

## Open questions
- AGPL-3.0 or MIT license (AGPL assumed for now).
