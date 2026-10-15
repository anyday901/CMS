# Add-ons and the event hook system

Add-ons are optional modules that staff install, configure and enable under **Settings > Add-ons**, without touching the core. Project management ships as one. This guide is for developers writing their own.

## Installing an add-on

1. Make its class available: list it in `config/addons.php` under `available`, or, from a Composer package's service provider, call `App\Addons\AddonManager::register(MyAddon::class)`.
2. Under **Settings > Add-ons**, click **Install**. This runs the add-on's database migrations; it starts disabled.
3. Fill in its settings under **Configure**, then **Enable** it. Its pages and menu links appear at once.

**Disable** hides it and keeps everything. **Uninstall** removes it, and with **Also delete its data** rolls back its migrations, dropping its tables. When a newer version of an add-on is deployed, the list offers **Update**, which runs its new migrations.

## Writing an add-on

Extend `App\Addons\Addon`:

```php
namespace Acme\Status;

use App\Addons\Addon;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use Illuminate\Support\Facades\Route;

class StatusAddon extends Addon
{
    public function key(): string { return 'status'; }          // unique; used in URLs, views and the database
    public function name(): string { return 'Status board'; }
    public function description(): string { return 'Shows paid invoices on a TV.'; }
    public function version(): string { return '1.0.0'; }

    public function settings(): array
    {
        return [
            'title' => ['label' => 'Board title', 'type' => 'text', 'default' => 'Today'],
            'api_key' => ['label' => 'Display API key', 'type' => 'secret'],
        ];
    }

    public function migrationsPath(): ?string { return __DIR__.'/../database/migrations'; }
    public function viewsPath(): ?string { return __DIR__.'/../resources/views'; }   // status::board

    public function routes(): void
    {
        $this->adminRoutes('status', function () {             // /admin/status, admin.status.*
            Route::get('/', BoardController::class)->name('index');
        });
    }

    public function adminLinks(): array
    {
        return ['admin.status.index' => ['Status', 'manage-billing']];   // only staff with that ability see it
    }

    public function boot(): void
    {
        $hooks = app(Hooks::class);
        $hooks->define('status.shown', 'Status board shown');          // webhooks and chat can subscribe
        $hooks->listen('invoice.paid', fn (HookEvent $event) => Board::add($event->data['total']));
    }
}
```

What each part does:

- **`settings()`**: fields shown under Configure. Types are `text`, `textarea`, `number`, `checkbox`, `select` (with `options`) and `secret` (not shown again; a blank field keeps it). Add `help`, `default` and Laravel validation `rules`. Read a value with `$this->setting('title')`. Settings are stored encrypted.
- **`migrationsPath()`**: normal Laravel migrations, run on install and update. Prefix your tables so they can't clash with the core's.
- **`routes()`**: always registered, so `php artisan route:cache` works; while the add-on is disabled its routes answer 404. `adminRoutes($path, ...)` adds staff login and two-factor checks under `/admin/{path}` with names `admin.{key}.*`; `portalRoutes($path, ...)` does the same for signed-in clients under `/portal/{path}` with names `portal.{key}.*`. Check abilities with the `can:` middleware as the core does.
- **`adminLinks()` / `portalLinks()`**: menu links while enabled. Portal links show to the account holder, not to contacts.
- **`boot()`**: runs on every request while enabled. Listen to events here.
- **`installed()`**: runs once after the first install, for seeding.

Views use the theme's components, `<x-admin-layout>` and `<x-portal-layout>`.

## A worked example: project management

The project management add-on in `app/Addons/ProjectManagement` uses each part of this API:

- `ProjectManagementAddon` has the key `projects` and is listed in `config/addons.php`.
- `migrationsPath()` points at its own `database/migrations`, which create the `pm_` tables on install; uninstalling with its data drops them.
- `viewsPath()` registers its `resources/views`, so controllers render `projects::admin.show` and `projects::portal.index` with the core layouts.
- `routes()` uses `adminRoutes('projects', ...)` and `portalRoutes('projects', ...)` for `/admin/projects` and `/portal/projects`, with `can:` middleware inside (creating projects needs `manage-clients`, invoicing time needs `manage-billing`).
- `settings()` declares the default hourly rate, whether invoiced time is taxed, whether clients see their projects in the portal, whether they see time logged, and the wording of invoice lines.
- `adminLinks()` adds **Projects** to the admin menu; `portalLinks()` adds it to the portal only while the portal setting is on.
- `boot()` defines `project.created`, `project.completed`, `project.task_completed` and `project.invoiced` for webhooks and chat, and listens to the core `client.merged` event to move a merged client's projects inside the merge.
- It stays out of the billing core: unbilled time becomes a draft invoice through the public `App\Billing\InvoiceEditor`, and the link from time entries to invoice lines lives in its own table.

In tests, install and enable it through `AddonManager`, then call `$manager->find('projects')->boot()`, since the app booted before it was enabled.

## The event hook system

`App\Hooks\Hooks` is a singleton. Every core event (see the table in [webhooks.md](webhooks.md)) is fired through it as an `App\Hooks\HookEvent` with:

- `key`: such as `invoice.paid`
- `data`: the record's main fields as an array (money in cents)
- `summary`: a sentence for people, such as "Invoice #1042 paid by Jane Doe: $15.00"
- `url`: the record's admin page
- `clientId`: the client it concerns, if any
- `id` and `occurredAt`

```php
$hooks = app(App\Hooks\Hooks::class);
$hooks->listen('ticket.opened', fn (HookEvent $e) => ...);   // one event
$hooks->listen(['service.suspended', 'service.terminated'], ...);
$hooks->listen(Hooks::ANY, ...);                              // every event
$hooks->define('myaddon.thing', 'My thing happened');          // a new event
$hooks->fire(new HookEvent('myaddon.thing', $data, 'Summary', $url, $clientId));
```

Events are fired after the database change that caused them is saved. A listener that throws is reported to the log and skipped, so a broken add-on can't stop a payment being recorded or other listeners running. Keep listeners quick, and dispatch a queued job for slow work.

Webhooks, chat notifications and SMS are themselves subscribers (`App\Hooks\HookSubscriber`, listed in `config/integrations.php`), so an event you define can be sent to all of them without more code.
