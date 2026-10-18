# System: health, maintenance, backups, logs and updates

The **System** pages are under **Settings**. They need the **Back up the database, install updates, themes and marketplace packages** permission (`manage-system`), which admins have; give it to other staff roles under **Staff**.

## Health

**Settings > System** checks the things CMS needs and says what to fix for each one:

- PHP version and extensions, the database and pending migrations;
- whether the scheduler and queue worker are running, and failed jobs;
- the mail settings, writable storage and free disk space;
- debug mode and HTTPS in production;
- when the last backup was made, maintenance mode, and whether an update is out.

Each check is **OK**, **Warning** or **Problem**.

For uptime monitors, `GET /health` answers `200` with `{"status":"ok"}` (or `"warning"`) and `503` when something has a problem. Set `HEALTH_TOKEN` in `.env` and call `/health?token=...` to also get each check's status. `/up` is a lighter check that only says the app boots.

## Maintenance mode

**Settings > Maintenance** takes the client portal, store and public pages offline with a message of your choosing, while you work on something. Visitors get a "We'll be right back" page (HTTP 503). Signed-in staff still see everything, with a banner saying maintenance mode is on, and the admin area, payment webhooks and unsubscribe links keep working.

This is separate from `php artisan down`, which takes everything offline, including the admin area. The updater uses that while it replaces files.

## Backups

**Settings > Backups** makes a full copy of the database as a compressed SQL file (`.sql.gz`) in `storage/app/backups`, and lists the copies to download or delete.

- **Back up now** makes one straight away (on the queue; it appears when it's done). Each backup reads the whole database from one consistent snapshot, like `mysqldump --single-transaction`, so the site can keep running while it's made.
- **Schedule**: every day, every week (Sundays) or off, at the time you set. The scheduler's cron job must be running.
- **Keep**: how many backups to keep on the server; after each new backup, the oldest beyond that number are deleted. Copies on another disk are left alone.
- **Also copy to**: a [filesystem disk](https://laravel.com/docs/filesystem) from `config/filesystems.php`, such as an S3 bucket, so a copy lives off the server.

Backups cover MySQL, MariaDB and SQLite databases, and don't need `mysqldump` on the server. They don't include uploaded files (`storage/app`) or `.env`; back those up separately, as [Installing](../install.md#backups) describes.

To restore one, on the server:

```sh
sudo -u www-data php artisan backup:restore                       # the latest
sudo -u www-data php artisan backup:restore backup-2026-10-15-013000.sql.gz
```

It asks before replacing the database, and puts the site in maintenance mode while it runs. `php artisan backup:run` makes a backup from the command line.

## Logs

**Settings > Logs** lists the log files Laravel writes in `storage/logs` (with `LOG_STACK=daily`, one a day, kept for `LOG_DAILY_DAYS` days), with each one's size and when it was last written. It also links to the other logs: the **activity log**, the **email log**, the **gateway log** and **cron status**, which need the settings permission.

Open a file to see its entries newest first: the level, the time and the message, with the stack trace or anything else written after it under **Details**. Filter by level (error, warning, info and the rest, each with how many there are), search the messages and stack traces, and page through 50 at a time. Only the last 2 MB of a file is read, so a big log can't slow the page down; **Download** gives the whole file. Change how much is read with `logging.viewer.max_bytes` in `config/logging.php`.

Only `.log` files directly in the log folder are listed, read or downloaded. Logs can hold client details and server paths, so each download is written to the activity log.

## PHP info

**Settings > PHP info** shows the PHP that runs CMS under the web server: its version, server API, operating system and `php.ini`, the settings that most often need changing (memory limit, execution time, upload and post sizes, input vars, timezone, OPcache and others), and the loaded extensions. Below that is `phpinfo()` itself, without its environment and request variables, since those hold the secrets in `.env`. The command line (cron and the queue worker) may use a different `php.ini`; check it with `php -i` on the server.

Logs and PHP info are off in demo mode.

## Updates

**Settings > Updates** shows the version running, checks GitHub for a newer release and shows its release notes. **Install update**:

1. downloads the release's `cms.zip` and checks its SHA-256 checksum;
2. takes a database backup and a copy of the current files;
3. puts the site in maintenance mode, replaces the files (keeping `.env`, `storage/`, `extensions/` and `themes/overrides/`, and deleting files the release no longer has from CMS's own folders: `app/`, `config/`, `database/`, `lang/`, `resources/` and `routes/`), runs the database migrations and clears the caches;
4. brings the site back up, and restarts the queue worker.

If any step fails, the old files (and, if the migrations had started, the database backup) are put back, and the page shows what went wrong. Keep your own changes out of CMS's folders: use `themes/overrides/` for templates and `extensions/` for add-ons.

The one-click updater only works on installs from a release download, where the web server can write to the app's folder. A git checkout, a Docker image or a read-only folder is updated the usual way instead, as [Updating](../install.md#updating) describes; the page says which applies. `php artisan cms:update` does the same from the command line (`--check` to only look). Set `UPDATES_ENABLED=false` to turn the updater off.
