# Provisioning modules

A provisioning module sets up and manages a service somewhere else: a control panel, a VPS host, a licensing server, your own API. Staff choose a module on a product and fill in its settings there; from then on, every service of that product is created, suspended, unsuspended and terminated through the module as its status changes.

The code is in `app/Provisioning`, and the built-in modules in `app/Provisioning/Modules`. Modules written for WHMCS can be uploaded instead of rewritten; see [WHMCS modules](../admin/whmcs-modules.md).

| Module | Key | Class | What it shows |
|---|---|---|---|
| Manual fulfillment | `manual` | `ManualFulfillment` | A module with no outside system: each action opens a task for staff |
| Webhook | `webhook` | `Webhook` | The smallest complete module: one HTTP call per action |
| cPanel / WHM | `cpanel` | `Cpanel` | A server module with single sign-on, usage, server health and account copying |
| Plesk | `plesk` | `Plesk` | A server module talking XML |
| DirectAdmin | `directadmin` | `DirectAdmin` | A server module with single sign-on and usage |
| Proxmox VE | `proxmox` | `Proxmox` | Long-running actions with `ProvisioningResult::later()`, reinstalls, and two kinds of account |
| Software licensing | `licensing` | `Licensing` | A module that keeps its state in the app itself |

## The `ProvisioningModule` interface

```php
namespace App\Provisioning;

use App\Models\Service;

interface ProvisioningModule
{
    /** Stored on products, e.g. "proxmox". Keep it stable. */
    public function key(): string;

    public function label(): string;

    /**
     * Settings shown on the product form and saved in the product's
     * module_config, keyed by field name.
     *
     * @return array<string, array{label: string, required?: bool, help?: string, type?: string, options?: array<string, string>, min?: int, default?: string}>
     */
    public function configFields(): array;

    /** Called when a service becomes active (first payment, or added without an invoice). */
    public function create(Service $service): ProvisioningResult;

    public function suspend(Service $service): ProvisioningResult;

    public function unsuspend(Service $service): ProvisioningResult;

    public function terminate(Service $service): ProvisioningResult;
}
```

- **`key()`** is saved in the product's `module` column. Don't change it once products use the module.
- **`configFields()`** are the product's settings for the module. `type` is `text` (the default), `textarea`, `url`, `number` (a whole number) or `select` with `options` (value => label); `min` is the shortest a text may be; `default` fills the field when a product first picks the module. The values are saved in the product's `module_config` array.
- **The four actions** each return a `ProvisioningResult`.

Register the class in `config/provisioning.php`:

```php
'modules' => [
    ManualFulfillment::class,
    Webhook::class,
    Cpanel::class,
    Plesk::class,
    DirectAdmin::class,
    Proxmox::class,
    Licensing::class,
    // App\Provisioning\Modules\MyPanel::class,
],
```

`App\Provisioning\ModuleRegistry` resolves each class through the service container, so the constructor can ask for what it needs. `all()` returns the modules keyed by key, `find($key)` one of them, and `withServers()` the ones that implement `UsesServers`.

### Reading the product's settings

`App\Provisioning\ProductSettings` reads a service's product settings, trimmed, with errors that tell staff what to fix:

```php
$settings = ProductSettings::of($service);
$settings->get('plan');                           // ?string, null when blank
$settings->required('package', 'WHM package');    // string, or a ModuleError naming the setting and product
$settings->wholeNumber('cores', 'CPU cores');     // int above zero, or a ModuleError
```

### Reporting back: `ProvisioningResult`

```php
ProvisioningResult::ok(?string $message = null, array $data = []);
ProvisioningResult::failed(string $message);
ProvisioningResult::later(int $seconds, ?string $message = null);
```

- **`ok()`**: the action is done. The message is shown to staff and written to the activity log. `$data` is merged into the service's `provisioning_data` (a JSON column), which is the place to keep the other system's ids, such as a remote account id, for later actions.
- **`failed()`**: the action didn't work. The service shows the message, staff who can manage clients are emailed, and the `staff.alert` event fires. Staff fix the cause and press **Run again**.
- **`later()`**: the other system is still working, such as copying a disk. The same action runs again after `$seconds`, and actions queued after it wait. Save what the next run needs on the service (in `provisioning_data`) before returning.

An exception thrown by an action is caught, logged and treated as `failed()` with the exception's message. For errors from the other system, throw `App\Provisioning\ModuleError` with a message staff can act on, or return `failed()`.

## How actions are queued and run

Module actions follow the service's status, through Laravel events wired in `AppServiceProvider`:

| Event | Action |
|---|---|
| `ServiceActivated` | `create` |
| `ServiceChanged` (an upgrade or downgrade) | `change_package` |
| `ServiceSuspended` | `suspend` |
| `ServiceUnsuspended` | `unsuspend` |
| `ServiceTerminated` | `terminate` |

`change_password` is queued when staff or the client save a new password, `reinstall` when either asks for one, and `copy_account` when a move to another server copies the account.

`App\Provisioning\Provisioner::queue($service, $action)` records the action on the service and dispatches an `App\Jobs\RunProvisioningAction` job. It skips products without a module, and optional actions the module doesn't support (`Provisioner::supports()`).

The job:

- **Runs one action at a time per service, in order.** Each queued action gets a sequence number; a job whose turn hasn't come yet waits 15 seconds and tries again, so a slow `create` can't land after a `terminate`.
- **Gives up after an hour** of waiting for earlier actions or for `later()` (a day for `copy_account`), marks the action failed and alerts staff.
- **Does not retry a failure on its own.** A half-finished action on another system is safer for staff to look at and run again than to repeat blindly.
- **Puts the service on a server first** for modules that implement `UsesServers`, before `create` (see [Servers](#modules-on-servers)).
- **Records the outcome** on the service (`provisioning_status` is `pending`, `done` or `failed`, with `provisioning_action`, `provisioning_error` and `provisioned_at`), merges the result's data into `provisioning_data`, and writes the activity log.

### Making actions safe to repeat

An action can run more than once: staff press **Run again** after a failure, or a reply was lost after the other system did the work. Make each action safe to repeat:

- Check before you change. The cPanel module's `createAccount()` looks the account up first and reports success when it already exists for the same domain; its `terminateAccount()` reports success when the account is already gone.
- Use `$service->provisioningOperation`. It is set while an action runs (it isn't saved), and is the same number on every run of one action, including staff retries of a failure, and different for each new action. The webhook module sends it as the `X-Webhook-Delivery` header, so the receiver can skip work it already did.

## A small module: the webhook module

`App\Provisioning\Modules\Webhook` is the one to copy for a module that calls one API. Trimmed:

```php
class Webhook implements ChangesPackage, ProvisioningModule
{
    public function key(): string { return 'webhook'; }

    public function label(): string { return 'Webhook'; }

    public function configFields(): array
    {
        return [
            'url' => ['label' => 'URL', 'type' => 'url', 'required' => true, 'help' => 'Receives a JSON POST for create, suspend, unsuspend, terminate and change_package.'],
            'secret' => ['label' => 'Signing secret', 'help' => 'Optional. Signs each request so the receiver can check it came from here.'],
        ];
    }

    public function create(Service $service): ProvisioningResult { return $this->send($service, 'create'); }
    // suspend, unsuspend, terminate and changePackage the same way

    private function send(Service $service, string $action): ProvisioningResult
    {
        $config = $service->product->module_config ?? [];
        $delivery = "service-{$service->id}-action-".($service->provisioningOperation ?? $service->provisioning_finished + 1);
        $body = json_encode($this->payload($service, $action, $delivery), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $headers = ['X-Webhook-Delivery' => $delivery, 'User-Agent' => config('app.name').' provisioning'];
        if (filled($config['secret'] ?? null)) {
            $headers['X-Webhook-Signature'] = 'sha256='.hash_hmac('sha256', $body, $config['secret']);
        }

        $response = Http::withHeaders($headers)->acceptJson()->timeout(30)
            ->withBody($body, 'application/json')->post($config['url']);

        if (! $response->successful()) {
            return ProvisioningResult::failed("The webhook answered HTTP {$response->status()}.");
        }

        $data = $response->json('data');

        return ProvisioningResult::ok($response->json('message'), is_array($data) && ! array_is_list($data) ? $data : []);
    }
}
```

The body has the `action`, the `delivery` id, `sent_at`, the `service` (id, label, status, product, billing cycle, recurring amount and currency, dates, custom fields by name, configurable options, and `provisioning_data` from earlier replies) and the `client` (id, name, company, email). Any 2xx answer counts as done; a JSON answer can include `message`, shown to staff, and `data`, an object saved on the service and sent with later actions.

## Optional interfaces

A module implements any of these as well, and gets more actions and buttons:

| Interface | Methods | What it adds |
|---|---|---|
| `ChangesPackage` | `changePackage(Service): ProvisioningResult` | Called after an upgrade or downgrade, with the service already on its new product and options. Modules without it are left alone when a service changes product. |
| `ChangesPassword` | `changePassword(Service): ProvisioningResult`, `canChangePassword(Service): bool` | Lets staff and clients set a new password. The new one is in `$service->password` while the action runs; if it fails, the old one is kept. |
| `Reinstalls` | `reinstall(Service): ProvisioningResult` | A **Reinstall** button for staff and clients on an active service, which wipes the account and sets it up again. |
| `SingleSignOn` | `loginUrl(Service, string $ip): string` | A **Log in to the control panel** button. See [Single sign-on](#single-sign-on). |
| `UsesServers` | see [below](#modules-on-servers) | Services go on servers staff add under **Products > Servers**. |
| `ReportsUsage` | `usage(Server, Collection $services): array` | Disk and bandwidth use, read every hour. See [Usage](#usage). |
| `ReportsServerHealth` | `health(Server): ServerHealth` | Facts and problems for server status monitoring. |
| `CopiesAccounts` | `copyProblem(Server $from, Server $to): ?string`, `copyAccount(Service, AccountMove): ProvisioningResult` | Copies an account with its data when staff move it to another server, as the `copy_account` action. |
| `SplitsAccountKinds` | `needsNewAccount(Product $from, Product $to): bool` | For modules with different kinds of account (a Proxmox VM and a container), where changing between them means a new account rather than a package change. |

## Modules on servers

A module that implements `App\Provisioning\UsesServers` works on servers staff add under **Products > Servers** and group into server groups. Before `create` runs, `App\Provisioning\ServerPicker` puts the service on a server from its product's server group (or any active server of the module, with no group), and the module reads it from `$service->server`.

```php
interface UsesServers
{
    /** The port used when a server doesn't set its own. */
    public function defaultPort(bool $secure): int;

    /** @return array<string, array{help: string, required?: bool}> */
    public function serverFields(): array;

    /** Checks the server can be reached with its details, for the Test connection button. */
    public function testConnection(Server $server): ProvisioningResult;

    /** Whether a service needs a domain name (its label) to be created. */
    public function needsDomain(): bool;

    /** @return array<string, string> label => value, shown on the service page in the portal */
    public function loginDetails(Service $service): array;
}
```

- **Server settings.** Every server has a name, hostname, port, whether it uses HTTPS (`secure`) and checks its certificate (`verify_tls`), and the most accounts it should hold. `serverFields()` says which of the other fields the module uses and explains each for staff: `username`, `password`, `api_token`, `ip_address` and `assigned_ips`. A field marked `required` must be filled in. The password and API token are stored encrypted. cPanel asks for:

    ```php
    public function serverFields(): array
    {
        return [
            'username' => ['help' => 'root, or the reseller that owns the accounts.', 'required' => true],
            'api_token' => ['help' => 'An API token from WHM > Development > Manage API Tokens. Recommended over the password.'],
            'password' => ['help' => 'Only needed without an API token.'],
        ];
    }
    ```

- **`defaultPort()`** is used when the server's port is blank, and by status monitoring to check the server answers. `$server->url($defaultPort)` gives `https://host:port` (http when not secure, IPv6 in brackets).
- **`needsDomain()`** makes the store ask for a domain, saved as the service's `label`.
- **`loginDetails()`** is shown to the client with the password once the service is set up. Values starting with `http` are linked.

### `ServerModule` and `ServerApi`

Most server modules extend `App\Provisioning\Modules\ServerModule`, which implements `ProvisioningModule`, `UsesServers`, `ChangesPackage` and `ChangesPassword` and leaves you the calls to the server:

```php
abstract protected function createAccount(Service $service, Server $server): ProvisioningResult;
abstract protected function suspendAccount(Service $service, Server $server): ProvisioningResult;
abstract protected function unsuspendAccount(Service $service, Server $server): ProvisioningResult;
abstract protected function terminateAccount(Service $service, Server $server): ProvisioningResult;
abstract protected function changeAccountPackage(Service $service, Server $server): ProvisioningResult;
abstract protected function changeAccountPassword(Service $service, Server $server): ProvisioningResult;
abstract protected function checkConnection(Server $server): ProvisioningResult;
```

It also:

- checks the service has a server, and that it's one of this module's;
- before `create`, checks the domain when `needsDomain()` is true, and gives the service a username (from `ServerAccount::newUsername()`, made from the domain and unused on the server; override `newUsername()` to change it) and a random password, unless staff set them first;
- turns a `ModuleError`, or a failed connection, into the action's failure message.

Use `$this->reach($service, fn (Server $server) => ...)` for calls outside the actions, such as `loginUrl()`, so the same checks apply.

`App\Provisioning\Modules\ServerApi` is a base for the HTTP client: `http()` gives a `PendingRequest` at the server's address, with its certificate setting, a user agent, a 10 second connect timeout and a 50 second timeout. `unexpected($system, $response, $what)` builds a `ModuleError` for an answer you can't use, with a hint about the login on 401 and 403. `WhmApi`, `PleskApi`, `DirectAdminApi` and `ProxmoxApi` extend it.

`App\Provisioning\ServerAccount` has the helpers for logins: `newPassword()`, `newUsername()`, `fillLogin()`, `username($service)` (or a `ModuleError` when the service has none) and `isDomain()`.

## Single sign-on

A module that implements `App\Provisioning\SingleSignOn` gives clients (and staff, on the admin service page) a button that opens the control panel already logged in:

```php
interface SingleSignOn
{
    /**
     * A one-time address that opens the control panel logged in as the
     * service's account. Called when the client asks, not on the queue.
     *
     * @throws ModuleError when the panel refuses or can't be reached
     */
    public function loginUrl(Service $service, string $ip): string;
}
```

`$ip` is the client's IP address, for panels that tie the session to it (it is an empty string when unknown). The button shows once the service is active and set up. The address is passed through `App\Provisioning\ControlPanelLogin`, which only sends the client there if it is http or https and its host is the service's server's hostname or IP address. cPanel's version, using WHM's `create_user_session`:

```php
public function loginUrl(Service $service, string $ip): string
{
    return $this->reach($service, function (Server $server) use ($service) {
        $url = $this->whm($server, 'create_user_session', ['user' => ServerAccount::username($service), 'service' => 'cpaneld'])['url'] ?? null;

        return is_string($url) && str_starts_with($url, 'http') ? $url : throw new ModuleError('WHM didn\'t return a login address.');
    });
}
```

## Usage

A module that implements `App\Provisioning\ReportsUsage` reports each account's disk and bandwidth use. `php artisan usage:collect` runs every hour from the scheduler and calls `usage()` once per server with the server's active and suspended services:

```php
/**
 * @param  Collection<int, Service>  $services  services with an account on $server
 * @return array<int, Usage> by service id; an account the server doesn't list is left out
 */
public function usage(Server $server, Collection $services): array;
```

Read the whole server in as few calls as it allows (cPanel uses two: `listaccts` and `showbw`). Return an `App\Provisioning\Usage` per service, in MB:

```php
new Usage(
    diskMb: 1200,
    diskLimitMb: 5120,          // null for unlimited or unknown
    bandwidthMb: 830,           // used so far this month
    bandwidthLimitMb: null,
    trafficCounter: null,       // or bytes counted since a counter started, for servers that report a running total
);
```

`Usage::parse('2.5G')` and `Usage::fromBytes($bytes)` convert what servers report into MB. Readings are kept per month for the service pages and for usage billing on products that have it.

## Testing a module

Tests run the queue synchronously (`QUEUE_CONNECTION=sync` in `phpunit.xml`), so activating a service runs `create` at once. Fake the other system with `Http::fake()` and check what was sent, as `tests/Feature/Servers/CpanelModuleTest.php` does:

```php
protected function setUp(): void
{
    parent::setUp();
    Notification::fake();
    $this->server = Server::factory()->create(['name' => 'cp1', 'hostname' => 'cp1.example.test', 'username' => 'reseller', 'api_token' => 'TOKEN1']);
    $product = Product::factory()->create(['name' => 'Starter', 'module' => 'cpanel', 'module_config' => ['package' => 'reseller_basic']]);
    $client = Client::factory()->create(['email' => 'ada@example.test']);
    $this->service = Service::factory()->for($product)->for($client)->pending()->create(['label' => 'example.com']);
}

public function test_creating_a_service_puts_it_on_a_server_and_makes_the_account(): void
{
    Http::fake([/* WHM's answers */]);

    app(ServiceLifecycle::class)->activate($this->service);

    $service = $this->service->fresh();
    $this->assertSame('done', $service->provisioning_status, (string) $service->provisioning_error);
    $this->assertSame($this->server->id, $service->server_id);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://cp1.example.test:2087/json-api/createacct?api.version=1'
        && $request['plan'] === 'reseller_basic');
}
```

For a module that isn't in `config/provisioning.php`, set it in the test: `config(['provisioning.modules' => [MyPanel::class]])`. `tests/Fakes/FakeProvisioningModule.php` is a module that records its calls and can be told to fail, for testing code around modules. Cover a repeated `create` and `terminate`, a failure from the server, and a server that can't be reached.
