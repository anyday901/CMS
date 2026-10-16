# Developer guide

CMS is a Laravel 13 app. Most of what it does can be extended without changing the core: payment gateways, provisioning modules and SMS providers are classes behind interfaces, listed in a config file; add-ons bring their own pages, settings and database tables; and every important change fires an event that code, webhooks, chat channels and SMS can follow.

A few rules hold across the codebase, and extensions should follow them too:

- **Money is an integer in minor units** (cents), with its currency next to it. Never a float. `App\Support\Money` parses and formats amounts.
- **Gateway and module code stays out of the billing core.** The core talks to interfaces; a gateway records payments through `App\Billing\PaymentRecorder`, and a module reports back with an `App\Provisioning\ProvisioningResult`.
- **Slow or outside work runs on the queue**: provisioning actions, webhook deliveries, chat messages, texts and client emails. Run a queue worker in development too, or set `QUEUE_CONNECTION=sync`.
- **CMS is AGPL-3.0-or-later.** Code that is part of the app, or distributed with it, is covered by that license.

## Extension points

| What | How you add one | Guide |
|---|---|---|
| **Add-ons** | A class extending `App\Addons\Addon`, listed in `config/addons.php` or registered from a Composer package. Staff install, configure and enable it under **Settings > Add-ons**. | [Add-ons](../addons.md) |
| **Event hooks** | `App\Hooks\Hooks::listen()` for core events, `define()` and `fire()` for your own. | [Event hooks](hooks.md) |
| **Outgoing webhooks, chat and SMS** | Staff subscribe these to events under **Settings**. In code they are `App\Hooks\HookSubscriber` classes listed in `config/integrations.php`. | [Webhooks and notifications](../webhooks.md), [Event hooks](hooks.md#subscribers-webhooks-chat-and-sms) |
| **Payment gateways** | A class implementing `App\Payments\Gateway` (and `App\Payments\RefundsPayments` for refunds), listed in `config/payments.php`. | [Payment gateways](gateways.md) |
| **Provisioning modules** | A class implementing `App\Provisioning\ProvisioningModule`, plus optional interfaces for servers, single sign-on, usage and more, listed in `config/provisioning.php`. | [Provisioning modules](provisioning.md) |
| **SMS providers** | A class implementing `App\Integrations\Sms\SmsProvider`, listed in `config/sms.php`. | [SMS providers](sms.md) |
| **Themes** | A folder under `themes/` with a `theme.json`, colours, an optional stylesheet and Blade view overrides for client-facing pages. | [Themes](themes.md) |
| **Translations** | JSON files under `lang/`, email templates per language, and strings wrapped in `__()`. | [Translations](translations.md) |
| **Marketplace packages** | An add-on or theme zipped with its `addon.json` or `theme.json`, listed in a registry staff install from. | [Marketplace packages](marketplace.md) |
| **REST API** | Not an extension point, but often the simplest way to connect another system. | [REST API](../api.md) |

## How the code is laid out

| Folder | What is in it |
|---|---|
| `app/Addons` | The add-on base class (`Addon`), `AddonManager`, and the bundled project management add-on in `ProjectManagement/` |
| `app/Affiliates` | The affiliate program, and its hook events (`AffiliateHooks`) |
| `app/Auth` | Portal logins for clients and contacts, two-factor (`Totp`), and checking old WHMCS password hashes |
| `app/Billing` | The billing core: invoice generation, `PaymentRecorder`, refunds (`RefundService`, `RefundReconciler`), disputes, credit, late fees, overdue handling, upgrades, cancellations and usage billing |
| `app/Clients` | Merging client accounts (`ClientMerger`) |
| `app/Console` | Artisan command classes (`admin:create`, `whmcs:import`, `whmcs:compare`) and `Scheduler`, which lists every scheduled task; the other commands, such as `billing:run`, are defined in `routes/console.php` |
| `app/Enums` | Statuses and billing cycles, such as `InvoiceStatus`, `ServiceStatus` and `BillingCycle` |
| `app/Events` | Laravel events the core dispatches (`InvoicePaid`, `ServiceActivated` and so on), which `App\Hooks\HookCatalog` turns into hook events |
| `app/Hooks` | The event hook system: `Hooks`, `HookEvent`, `HookCatalog`, `HookSubscriber` and `Payload` |
| `app/Http` | Controllers for the admin area (`Admin/`), the portal (`Portal/`), the store (`Store/`), the REST API (`Api/`) and payment webhooks, plus middleware and API resources |
| `app/Import` | The WHMCS importer |
| `app/Integrations` | Outgoing webhooks (`Webhooks/`), chat notifications (`Chat/`) and SMS (`Sms/`) |
| `app/Jobs` | Queued jobs: provisioning actions, webhook deliveries, chat messages, texts and emails |
| `app/Licensing` | Software license checks |
| `app/Mail` | Client emails, email templates and mass mail |
| `app/Models` | Eloquent models |
| `app/Monitoring` | Server status monitoring |
| `app/Notifications` | `StaffAlert`, the email to staff when something needs a person |
| `app/Ordering` | The cart and checkout |
| `app/Payments` | Payment gateway interfaces, `GatewayRegistry`, and the gateways in `Gateways/` |
| `app/Privacy` | GDPR data export and anonymising |
| `app/Provisioning` | Provisioning module interfaces, `Provisioner`, `ModuleRegistry`, servers and usage, and the modules in `Modules/` |
| `app/Reports` | The reports under **Reports** |
| `app/Security` | CAPTCHA, email verification, new device alerts and login security |
| `app/Support` | Small helpers used everywhere: `Money`, `Settings`, `Markdown`, search |
| `app/Tickets` | Support tickets: email piping, the spam filter, escalation, merging and business hours |

Views are Blade templates under `resources/views` (`admin/`, `portal/`, `store/` and shared `components/`), styled with Tailwind CSS from `resources/css/app.css`. Routes are in `routes/web.php` and `routes/api.php`, and tests in `tests/Feature` and `tests/Unit`; `tests/Fakes` has a fake add-on and a fake provisioning module to copy from.

## Working on CMS itself

Set up a local copy as in the [README](https://github.com/anyday901/CMS/blob/main/README.md#local-development), then:

```sh
php artisan test        # the whole suite; CI runs it on PHP 8.3 to 8.5
npm run build           # after changing views or CSS
```

Check the [product spec](../spec.md) before adding a feature: v1 is deliberately narrow. Each change updates `CHANGELOG.md` (under **Unreleased**), `TODO.md`, and the README or these docs when setup or features change.

To build these docs:

```sh
python3 -m venv ~/.venvs/cms-docs
~/.venvs/cms-docs/bin/pip install -r requirements-docs.txt
~/.venvs/cms-docs/bin/mkdocs serve           # http://127.0.0.1:8000, reloads as you edit
~/.venvs/cms-docs/bin/mkdocs build --strict  # writes the site to site/
```
