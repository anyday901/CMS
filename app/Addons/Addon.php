<?php

namespace App\Addons;

use App\Http\Middleware\EnsureClientCanLogIn;
use App\Http\Middleware\RequireStaffTwoFactor;
use Closure;
use Illuminate\Support\Facades\Route;

/**
 * An add-on module: a feature that staff install, configure and enable
 * under Settings > Add-ons, such as project management. Extend this class,
 * list it in config/addons.php (or call AddonManager::register() from a
 * package's service provider) and see docs/addons.md.
 *
 * An add-on can bring its own database tables (migrationsPath), routes,
 * views (resources/views beside the class, as "key::view"), menu links and
 * settings, and listen to events through App\Hooks\Hooks in boot().
 */
abstract class Addon
{
    /** A short unique key, such as "projects". Used in URLs, view names and the database. */
    abstract public function key(): string;

    abstract public function name(): string;

    /** One or two sentences for the add-ons list. */
    abstract public function description(): string;

    public function version(): string
    {
        return '1.0.0';
    }

    public function author(): string
    {
        return '';
    }

    /**
     * Settings staff fill in under Configure. Each field: label, type (text,
     * textarea, number, checkbox, select or secret), and optionally help,
     * options (for select), rules (Laravel validation) and default.
     *
     * @return array<string, array{label: string, type: string, help?: string, options?: array<string, string>, rules?: list<mixed>, default?: mixed}>
     */
    public function settings(): array
    {
        return [];
    }

    /** A folder of migrations run on install and rolled back when uninstalled with its data. */
    public function migrationsPath(): ?string
    {
        return null;
    }

    /** A folder of JSON translation files (en.json, de.json, …) for the add-on's strings, merged in while it is installed. */
    public function langPath(): ?string
    {
        return null;
    }

    /** A folder of Blade views, used as "key::name". */
    public function viewsPath(): ?string
    {
        return null;
    }

    /**
     * Registers the add-on's routes. Called whether or not it is enabled (so
     * route caching works); a disabled add-on's routes answer 404. Use
     * adminRoutes() and portalRoutes() for the usual login checks.
     */
    public function routes(): void {}

    /** Called on every request while the add-on is enabled: listen to hooks here. */
    public function boot(): void {}

    /**
     * Called on every request while the add-on is installed, even when it is
     * disabled, before boot(). Listen here to hooks that keep the add-on's
     * data correct, such as client.merged, so turning the add-on off never
     * leaves its rows behind.
     */
    public function bootInstalled(): void {}

    /**
     * Links in the admin menu while enabled: route name => label. An entry
     * can be [label, ability] to show it only to staff with that ability.
     *
     * @return array<string, string|array{0: string, 1: string}>
     */
    public function adminLinks(): array
    {
        return [];
    }

    /** @return array<string, string> links in the client portal menu while enabled: route name => label */
    public function portalLinks(): array
    {
        return [];
    }

    /** Runs once after the add-on's migrations, on install. */
    public function installed(): void {}

    /** A saved setting, or the field's default. */
    public function setting(string $name, mixed $default = null): mixed
    {
        return app(AddonManager::class)->settings($this->key())[$name]
            ?? $this->settings()[$name]['default']
            ?? $default;
    }

    /** Admin pages under /admin/{path}, named admin.{key}.*, for signed-in staff. */
    protected function adminRoutes(string $path, Closure $routes): void
    {
        Route::prefix('admin/'.trim($path, '/'))->name('admin.'.$this->key().'.')
            ->middleware(['auth:web', RequireStaffTwoFactor::class])->group($routes);
    }

    /** Client portal pages under /portal/{path}, named portal.{key}.*, for signed-in clients. */
    protected function portalRoutes(string $path, Closure $routes): void
    {
        Route::prefix('portal/'.trim($path, '/'))->name('portal.'.$this->key().'.')
            ->middleware(['auth:client', EnsureClientCanLogIn::class])->group($routes);
    }
}
