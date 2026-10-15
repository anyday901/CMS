<?php

namespace App\Addons;

use App\Http\Middleware\EnsureAddonEnabled;
use App\Marketplace\ExtensionAddons;
use App\Models\Activity;
use App\Models\InstalledAddon;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use PDOException;

/**
 * Finds add-on modules, installs, enables and configures them, and boots
 * the enabled ones on each request.
 */
class AddonManager
{
    /** @var list<class-string<Addon>> added by packages at runtime */
    private static array $registered = [];

    /** @var Collection<string, InstalledAddon>|null */
    private ?Collection $installed = null;

    /** @var Collection<string, Addon>|null */
    private ?Collection $available = null;

    /** Adds an add-on class from a package's service provider. */
    public static function register(string $class): void
    {
        self::$registered[] = $class;
    }

    /** @return Collection<string, Addon> every add-on that can be installed, by key */
    public function available(): Collection
    {
        return $this->available ??= collect([...config('addons.available', []), ...self::$registered, ...ExtensionAddons::classes()])
            ->unique()
            ->map(fn (string $class) => app($class))
            ->keyBy(fn (Addon $addon) => $addon->key());
    }

    /** Forgets the add-ons found so far, after the marketplace adds or removes one. */
    public function refresh(): void
    {
        $this->available = null;
        $this->installed = null;
    }

    public function find(string $key): ?Addon
    {
        return $this->available()->get($key);
    }

    /** @return Collection<string, InstalledAddon> */
    public function installed(): Collection
    {
        if ($this->installed === null) {
            try {
                $this->installed = InstalledAddon::all()->keyBy('key');
            } catch (QueryException|PDOException) {
                // Not migrated yet.
                return collect();
            }
        }

        return $this->installed;
    }

    public function isInstalled(string $key): bool
    {
        return $this->installed()->has($key);
    }

    public function enabled(string $key): bool
    {
        return $this->find($key) !== null && (bool) $this->installed()->get($key)?->enabled;
    }

    /** @return Collection<string, Addon> */
    public function enabledAddons(): Collection
    {
        return $this->available()->filter(fn (Addon $addon) => $this->enabled($addon->key()));
    }

    /** @return array<string, mixed> */
    public function settings(string $key): array
    {
        return $this->installed()->get($key)?->settings ?? [];
    }

    /**
     * Registers every add-on's views and routes (routes answer 404 while it is
     * disabled), then runs bootInstalled() for installed ones and boot() for
     * enabled ones. Called once at startup.
     */
    public function boot(): void
    {
        foreach ($this->available() as $key => $addon) {
            if ($addon->viewsPath()) {
                View::addNamespace($key, $addon->viewsPath());
            }
            Route::middleware(['web', EnsureAddonEnabled::class.':'.$key])->group(fn () => $addon->routes());
        }

        foreach ($this->available() as $key => $addon) {
            if ($this->isInstalled($key)) {
                if ($addon->langPath() && is_dir($addon->langPath())) {
                    app('translator')->addJsonPath($addon->langPath());
                }
                $addon->bootInstalled();
            }
        }

        foreach ($this->enabledAddons() as $addon) {
            $addon->boot();
        }
    }

    /** Runs the add-on's migrations and records it, disabled until staff enable it. */
    public function install(string $key): InstalledAddon
    {
        $addon = $this->find($key) ?? throw new InvalidArgumentException("There is no add-on called {$key}.");

        if ($this->isInstalled($key)) {
            throw new InvalidArgumentException("{$addon->name()} is already installed.");
        }

        $this->migrate($addon, 'migrate');

        $record = InstalledAddon::create(['key' => $key, 'version' => $addon->version(), 'enabled' => false, 'settings' => []]);
        $this->installed = null;
        $addon->installed();
        Activity::record("Installed the {$addon->name()} add-on", $record);

        return $record;
    }

    public function setEnabled(string $key, bool $enabled): void
    {
        $record = $this->record($key);
        $record->update(['enabled' => $enabled]);
        $this->installed = null;
        Activity::record(($enabled ? 'Enabled' : 'Disabled')." the {$this->find($key)?->name()} add-on", $record);
    }

    /** @param  array<string, mixed>  $values */
    public function saveSettings(string $key, array $values): void
    {
        $record = $this->record($key);
        $record->update(['settings' => $values]);
        $this->installed = null;
        Activity::record("Changed the {$this->find($key)?->name()} add-on's settings", $record);
    }

    /**
     * Removes the add-on. With $removeData its migrations are rolled back,
     * deleting its tables; otherwise they stay for a later reinstall.
     */
    public function uninstall(string $key, bool $removeData): void
    {
        $record = $this->record($key);
        $addon = $this->find($key);

        if ($removeData && $addon) {
            $this->migrate($addon, 'migrate:reset');
        }

        $record->delete();
        $this->installed = null;
        Activity::record('Uninstalled the '.($addon?->name() ?? $key).' add-on'.($removeData ? ' and deleted its data' : ''));
    }

    /**
     * Menu links of enabled add-ons that this staff member may see.
     *
     * @return array<string, string> route name => label
     */
    public function adminLinks(User $user): array
    {
        return $this->enabledAddons()->flatMap(fn (Addon $addon) => collect($addon->adminLinks())
            ->reject(fn ($link) => is_array($link) && ! $user->allows($link[1]))
            ->map(fn ($link) => is_array($link) ? $link[0] : $link))->all();
    }

    /** @return array<string, string> route name => label */
    public function portalLinks(): array
    {
        return $this->enabledAddons()->flatMap(fn (Addon $addon) => $addon->portalLinks())->all();
    }

    /** The upgrade runs new migrations an add-on brought in a later version. */
    public function upgrade(string $key): void
    {
        $record = $this->record($key);
        $addon = $this->find($key) ?? throw new InvalidArgumentException("There is no add-on called {$key}.");
        $this->migrate($addon, 'migrate');
        $record->update(['version' => $addon->version()]);
        $this->installed = null;
        Activity::record("Updated the {$addon->name()} add-on to {$addon->version()}", $record);
    }

    private function record(string $key): InstalledAddon
    {
        return InstalledAddon::where('key', $key)->first() ?? throw new InvalidArgumentException("The {$key} add-on isn't installed.");
    }

    private function migrate(Addon $addon, string $command): void
    {
        $path = $addon->migrationsPath();

        if ($path === null) {
            return;
        }

        $exit = Artisan::call($command, ['--path' => $path, '--realpath' => true, '--force' => true]);

        if ($exit !== 0) {
            throw new AddonMigrationFailed("The {$addon->name()} add-on's database changes failed: ".trim(Artisan::output()));
        }
    }
}
