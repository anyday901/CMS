# Installing CMS on a server

This guide sets up CMS on one Linux server with Nginx, PHP-FPM, MariaDB, a cron entry for the scheduler and a queue worker under systemd. It uses Ubuntu 24.04; other distributions work the same way with their own package names. If you would rather run it in containers, see [Docker Compose](#docker-compose) at the end.

Commands that start with `sudo` run as root. The app runs as the `www-data` user, and lives in `/var/www/cms`. Replace `billing.example.com` with your domain.

## 1. Install the packages

```sh
sudo apt update
sudo apt install nginx mariadb-server unzip git \
  php8.3-fpm php8.3-cli php8.3-bcmath php8.3-curl php8.3-gd php8.3-intl \
  php8.3-mbstring php8.3-mysql php8.3-xml php8.3-zip php8.3-redis
```

Install Composer from [getcomposer.org](https://getcomposer.org/download/), and Node 22 from NodeSource (Ubuntu's own Node is too old to build the frontend):

```sh
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

Redis is optional. The default queue and cache use the database, which is fine for a few thousand clients. To use Redis for the queue instead, `sudo apt install redis-server` and set `QUEUE_CONNECTION=redis` in step 3.

## 2. Create the database

```sh
sudo mariadb
```

```sql
CREATE DATABASE cms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cms'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON cms.* TO 'cms'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 3. Get the code and configure it

```sh
sudo mkdir -p /var/www/cms
sudo chown $USER:www-data /var/www/cms
git clone https://github.com/anyday901/CMS.git /var/www/cms
cd /var/www/cms
git checkout v1.0.0   # or the latest release tag

composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
php artisan key:generate
```

Edit `.env`. At least:

```dotenv
APP_NAME="Your Company"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://billing.example.com
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cms
DB_USERNAME=cms
DB_PASSWORD=a-long-random-password

MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="billing@example.com"
MAIL_FROM_NAME="${APP_NAME}"

BILLING_CURRENCY=USD
BILLING_COMPANY_NAME="Your Company"
BILLING_COMPANY_ADDRESS="1 Main St\nSpringfield"
```

The [README](../README.md) lists every billing, payment gateway and ticket setting. Then create the tables, cache the configuration and make the writable folders belong to the web server:

```sh
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo -u www-data php artisan admin:create
```

`admin:create` asks for the first staff account. Run `php artisan config:cache` again whenever you change `.env`.

## 4. PHP-FPM

Ticket attachments are up to 5 files of 10 MB each, so raise PHP's upload limits. Create `/etc/php/8.3/fpm/conf.d/90-cms.ini`:

```ini
upload_max_filesize = 10M
post_max_size = 60M
memory_limit = 256M
expose_php = Off
```

```sh
sudo systemctl restart php8.3-fpm
```

## 5. Nginx

Create `/etc/nginx/sites-available/cms`:

```nginx
server {
    listen 80;
    server_name billing.example.com;
    root /var/www/cms/public;
    index index.php;

    client_max_body_size 60m;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location /build/ {
        expires max;
        access_log off;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```sh
sudo ln -s /etc/nginx/sites-available/cms /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Then add HTTPS. With Let's Encrypt:

```sh
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d billing.example.com
```

Payment webhooks (PayPal, Square) need HTTPS, so do this before setting them up.

## 6. Cron for the scheduler

The scheduler runs the nightly billing run (renewal and overage invoices, late fees, reminders, suspensions), activity log clean-up, the hourly read of disk and bandwidth use from your servers and the five-minute server status checks. Add it to the web server user's crontab:

```sh
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/cms && php artisan schedule:run >> /dev/null 2>&1
```

## 7. Queue worker

Emails, provisioning actions and webhooks run on the queue, so a worker must always be running. Create `/etc/systemd/system/cms-queue.service`:

```ini
[Unit]
Description=CMS queue worker
After=network.target mariadb.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/cms
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now cms-queue
sudo systemctl status cms-queue
```

The worker restarts itself every hour (`--max-time`), and systemd starts it again.

## 8. Check it works

Open `https://billing.example.com/admin`, log in with the account from step 3, and:

- add a product and a client, then a service for that client;
- run `sudo -u www-data php artisan billing:run` and check it reports what it did;
- send the client a password link from their page and check the email arrives (and shows as sent under **Emails**).

If an email stays queued, the queue worker isn't running: check `journalctl -u cms-queue`. Errors are logged to `storage/logs/laravel.log`.

## Updating

```sh
cd /var/www/cms
sudo -u www-data php artisan down
git fetch --tags && git checkout v1.x.y
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo -u www-data php artisan queue:restart
sudo -u www-data php artisan up
```

Read [CHANGELOG.md](../CHANGELOG.md) for anything a release asks you to do.

## Backups

Back up the database and `storage/app` (ticket attachments) every night, and keep copies off the server. For example, in root's crontab:

```cron
30 2 * * * mysqldump --single-transaction cms | gzip > /var/backups/cms-$(date +\%F).sql.gz
45 2 * * * tar -czf /var/backups/cms-files-$(date +\%F).tar.gz -C /var/www/cms storage/app
```

Keep `.env` somewhere safe too: without `APP_KEY` you can't read encrypted data or keep clients logged in.

## Moving from WHMCS

`php artisan whmcs:import` copies clients and their contacts, products with prices and configurable options, custom fields, services, invoices, payments and refunds, credit balances, admins as staff accounts, ticket departments, custom fields, tags, tickets (guest tickets too), replies and feedback ratings, the knowledge base and announcements with their translations, network issues, downloads and escalation rules from a WHMCS database. Clients, services, invoices and tickets keep their WHMCS ids, so invoice and ticket numbers don't change. WHMCS admins become staff accounts with their WHMCS passwords, except disabled admins and any whose email already has a staff account here; check their roles under **Staff** afterwards.

Steps 2 to 4 can also be done in the admin area under **Settings > Import from WHMCS**, a walkthrough that connects to the WHMCS database (from `.env` or details you type in), runs the dry run, shows the report and then imports. The command line is more reliable for very large installs, since a browser request can time out behind a proxy.

1. **Get a copy of the WHMCS database.** Either give the importer a read-only MySQL user on the live WHMCS database, or restore a dump into a new database:

   ```sh
   sudo mariadb -e "CREATE DATABASE whmcs_copy CHARACTER SET utf8mb4; GRANT SELECT ON whmcs_copy.* TO 'cms'@'localhost';"
   sudo mariadb whmcs_copy < whmcs-dump.sql
   ```

2. **Point the importer at it** in `.env`, then `php artisan config:cache`:

   ```dotenv
   WHMCS_DB_HOST=127.0.0.1
   WHMCS_DB_DATABASE=whmcs_copy
   WHMCS_DB_USERNAME=cms
   WHMCS_DB_PASSWORD=a-long-random-password
   ```

3. **Do a dry run** on a fresh install (the import refuses to run if there are already clients, products, services, invoices or tickets):

   ```sh
   sudo -u www-data php artisan whmcs:import --dry-run
   ```

   It imports everything, prints how many records it found, imported and skipped, compares totals (recurring revenue, balance due, payments, credit) between WHMCS and the import, then undoes it all. The full report, listing every skipped record and why, is saved in `storage/app/whmcs/`. Fix what you can in WHMCS (duplicate client emails, services on deleted products) and run it again until you're happy.

4. **Import for real.** To bring ticket attachments and the files on your Downloads page over, pass the WHMCS attachments and downloads folders:

   ```sh
   sudo -u www-data php artisan whmcs:import --attachments=/path/to/whmcs/attachments --downloads=/path/to/whmcs/downloads --hash='the cc_encryption_hash from WHMCS configuration.php'
   ```

   Escalation rules come over turned off: check each one under **Tickets > Escalation rules** before turning it on.

   Everything is imported in one database transaction, so a failure part way leaves nothing behind.

5. **Run both side by side for a billing cycle.** Before turning WHMCS off, check this app would bill the same:

   ```sh
   sudo -u www-data php artisan whmcs:compare --simulate --from=2026-12-01 --to=2026-12-31
   ```

   With `--simulate`, this app generates its renewal invoices for those dates and throws them away (no emails, nothing saved), then lists every renewal where it and WHMCS disagree: a different amount or tax, or an invoice only one of them made. WHMCS add-on and domain renewals always show as WHMCS-only, since those aren't imported. Only WHMCS invoices made after the import are compared, so you can run this while WHMCS keeps billing as usual. Without `--simulate` it compares the invoices this app has really made, if you let its billing run go live for a cycle. Don't let both systems email clients at once: keep `MAIL_MAILER=log` here until you switch.

6. **Switch over.** Turn off the WHMCS cron, move payment gateway webhooks to this app, point your support addresses at it (see [Tickets by email](../README.md#tickets-by-email)), set real `MAIL_*` settings, and tell clients the new address.

What changes for clients:

- They log in with their client email address and their WHMCS password. The first time it works, it is stored again with this app's hashing and the old WHMCS hash is dropped. Very old WHMCS password formats that can't be checked fall back to **Forgot or never set your password?** on the login page. WHMCS 8 users who logged in with a different email than their client account's should use the client email.
- Replies to old WHMCS ticket emails (with `[Ticket ID: 123456]` in the subject) still go to the right ticket.
- Contacts with their own WHMCS logins become contacts without a login, since contacts can't log in here yet.
- Custom WHMCS invoice numbers (from sequential paid invoice numbering) are kept in the invoice notes; the invoice number here is the WHMCS invoice id.
- Products on the WHMCS cPanel, Plesk and DirectAdmin modules keep their module, package or service plan and server group, and services keep their server and login, so clients see how to log in and can use **Log in to the control panel**. Pass `--hash=` (or fill in **WHMCS encryption hash** in the walkthrough) with the `$cc_encryption_hash` from WHMCS's `configuration.php` to bring the passwords too. Proxmox products need their template, cores and memory set, and other WHMCS server modules need a provisioning module chosen on the product page.
- Product add-ons and domains aren't supported yet, so they aren't imported. The report lists every active one, so you can fold an add-on into its service's price or bill it by hand.
- Tax rules aren't imported. Add yours under **Tax** before the first billing run; the report reminds you when WHMCS charged tax.
- Configurable options have one setup fee here. Where WHMCS had a different setup fee per billing cycle, the monthly one is kept and the report says so.

## Docker Compose

The repository includes a `Dockerfile` and `docker-compose.yml` that run the app with Nginx, MariaDB, Redis, a queue worker and the scheduler. They suit a single server, or trying the app out.

```sh
git clone https://github.com/anyday901/CMS.git && cd CMS
cp .env.example .env
```

Set at least `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` and `DB_PASSWORD` (any long random password; the database container is created with it) in `.env`, plus the mail and billing settings above. Then generate an app key into `.env` and start everything:

```sh
docker compose run --rm --no-deps app php artisan key:generate --show
# paste the printed key into .env as APP_KEY=base64:...
docker compose up -d --build
docker compose exec app php artisan admin:create
```

The app is then on port 8080 (set `APP_PORT` to change it). Put your usual HTTPS reverse proxy (Caddy, Nginx, Traefik) in front of it. The containers:

| Service | Does |
| --- | --- |
| `web` | Nginx: serves the built assets and passes requests to `app` |
| `app` | PHP-FPM; runs database migrations when it starts |
| `queue` | `php artisan queue:work` |
| `scheduler` | `php artisan schedule:work`, the billing run and clean-up |
| `db` | MariaDB 11.4, data in the `db` volume |
| `redis` | Redis for the queue |

Uploaded files and logs are in the `storage` volume. To update, `git pull` and `docker compose up -d --build`. To run artisan commands, such as the WHMCS import, use `docker compose exec app php artisan ...`; set `WHMCS_DB_HOST` to an address the container can reach.

For development, the [local setup in the README](../README.md#local-development) with SQLite is quicker than rebuilding images.
