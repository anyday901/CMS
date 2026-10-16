# Running a demo instance

Demo mode turns an install into a public demo anyone can sign in to and click around: it fills in a sample hosting company, shows the logins on the login pages, sends no email, and resets itself every few hours.

!!! danger "Use a separate install"
    Resetting the demo wipes its database. Never turn demo mode on for an install with real data.

## Set it up

1. Install CMS as [Installing](install.md) describes, on its own server or container, with its own database.
2. In `.env`:

    ```dotenv
    DEMO_MODE=true
    DEMO_RESET_HOURS=3
    DEMO_STAFF_EMAIL=demo@example.com
    DEMO_CLIENT_EMAIL=client@example.com
    DEMO_PASSWORD=demo
    INSTALLER_ENABLED=false
    MARKETPLACE_INSTALLS=false
    UPDATES_ENABLED=false
    ```

3. Fill in the demo data:

    ```sh
    sudo -u www-data php artisan config:cache
    sudo -u www-data php artisan demo:reset
    ```

4. Make sure the scheduler's cron job runs. It calls `demo:reset` every `DEMO_RESET_HOURS` hours.

## What demo mode does

- Staff sign in at `/admin` with the demo staff login (an admin), clients at `/portal` with the demo client login. Both are shown on the login pages, and a banner on every page says it's a demo.
- Email goes to the log only, whatever the mail settings say.
- Things that could lock others out or reach outside the demo are refused with a message: staff accounts and passwords, mail settings, backups, maintenance mode, updates and the marketplace.
- Everything else, such as adding clients, invoices, payments and tickets, works as normal until the next reset.

## The demo data

`demo:reset` runs `migrate:fresh` with `Database\Seeders\DemoSeeder`: two staff accounts, nine clients (some with German, French or Spanish as their language), products in two groups, services, paid and unpaid invoices, tickets, knowledge base articles and an announcement. Edit the seeder to change it.
