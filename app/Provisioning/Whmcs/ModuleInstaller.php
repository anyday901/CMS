<?php

namespace App\Provisioning\Whmcs;

use App\Models\Activity;
use App\Models\WhmcsModule;
use App\System\Archive;
use App\System\ArchiveException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * Takes an uploaded WHMCS server module (one PHP file, or a zip of the
 * module folder or of WHMCS's modules/ folder), checks its code, and puts it
 * in extensions/whmcs/servers/{name}. New modules start turned off, so staff
 * read the check first; uploading a module again replaces its files.
 */
class ModuleInstaller
{
    /** Zips can't unpack to more than this. */
    private const MAX_BYTES = 50 * 1024 * 1024;

    /** WHMCS module names: the folder, file and function prefix. */
    private const NAME = '/^[A-Za-z]\w{0,63}$/';

    public function __construct(private WhmcsModules $modules) {}

    /** @throws ModuleUploadException */
    public function install(string $path, string $originalName): WhmcsModule
    {
        $staging = storage_path('app/whmcs-modules/'.Str::random(12));

        try {
            [$folder, $name, $notes] = $this->unpack($path, $originalName, $staging);
            $report = ModuleInspector::inspect($folder, $name);

            if ($report['blockers'] !== []) {
                throw new ModuleUploadException(implode(' ', $report['blockers']));
            }

            $report['warnings'] = [...$notes, ...$report['warnings']];
            $record = WhmcsModule::all()->first(fn (WhmcsModule $module) => strtolower($module->name) === strtolower($name));

            if ($record !== null && $record->name !== $name) {
                throw new ModuleUploadException("A module called {$record->name} is already here. WHMCS module names are the same in any case, so remove it first.");
            }

            $this->replace($folder, $this->modules->folder($name), fn () => $record?->enabled
                ? $record->fill(['meta' => $meta = ModuleInspection::run($this->modules->folder($name)."/{$name}.php"), 'display_name' => $meta['display_name']])
                : null);
        } catch (ArchiveException $e) {
            throw new ModuleUploadException($e->getMessage(), previous: $e);
        } finally {
            File::deleteDirectory($staging);
        }

        if ($record?->enabled) {
            // Queue workers keep the old code loaded until they restart.
            Artisan::call('queue:restart');
        }

        $record ??= new WhmcsModule(['name' => $name, 'display_name' => $name]);
        $record->fill(['report' => $report])->save();
        Activity::record(($record->wasRecentlyCreated ? 'Uploaded' : 'Replaced')." the WHMCS module {$name}", $record);

        return $record;
    }

    /** Loads the module to read its settings, then turns it on. @throws ModuleUploadException */
    public function enable(WhmcsModule $module): void
    {
        $meta = ModuleInspection::run($this->modules->file($module));
        $module->update(['enabled' => true, 'meta' => $meta, 'display_name' => $meta['display_name']]);
        Activity::record("Enabled the WHMCS module {$module->name}", $module);
    }

    /** @throws ModuleUploadException */
    public function disable(WhmcsModule $module): void
    {
        $this->ensureUnused($module, 'turned off');
        $module->update(['enabled' => false]);
        Activity::record("Disabled the WHMCS module {$module->name}", $module);
    }

    /** @throws ModuleUploadException */
    public function remove(WhmcsModule $module): void
    {
        $this->ensureUnused($module, 'removed');
        File::deleteDirectory($this->modules->folder($module->name));
        $module->delete();
        Activity::record("Removed the WHMCS module {$module->name}");
    }

    private function ensureUnused(WhmcsModule $module, string $verb): void
    {
        if ($this->modules->inUse($module)) {
            throw new ModuleUploadException("{$module->display_name} is used by products or servers, so it can't be {$verb}. Move them to another module first.");
        }
    }

    /**
     * Unpacks the upload into $staging.
     *
     * @return array{string, string, list<string>} the module's folder, its name, and notes for staff
     */
    private function unpack(string $path, string $originalName, string $staging): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === 'php') {
            $name = $this->name(pathinfo($originalName, PATHINFO_FILENAME));
            File::ensureDirectoryExists("{$staging}/{$name}");
            File::copy($path, "{$staging}/{$name}/{$name}.php");

            return ["{$staging}/{$name}", $name, []];
        }

        if ($extension !== 'zip') {
            throw new ModuleUploadException('Upload the module\'s PHP file, or a zip of it.');
        }

        Archive::extract($path, "{$staging}/zip", self::MAX_BYTES);

        return $this->locate("{$staging}/zip");
    }

    /** @return array{string, string, list<string>} */
    private function locate(string $root): array
    {
        $php = collect(File::allFiles($root))->filter(fn (SplFileInfo $file) => strtolower($file->getExtension()) === 'php');

        // A WHMCS module is a folder with a PHP file of the same name: servers/mypanel/mypanel.php.
        $modules = $php->filter(fn (SplFileInfo $file) => $file->getPath() !== $root && $file->getFilenameWithoutExtension() === basename($file->getPath()))->values();
        $servers = $modules->filter(fn (SplFileInfo $file) => basename(dirname($file->getPath())) === 'servers')->values();
        $pick = match (true) {
            $servers->count() === 1 => $servers->first(),
            $servers->count() === 0 && $modules->count() === 1 => $modules->first(),
            default => null,
        };

        if ($pick !== null) {
            $others = $modules->reject(fn (SplFileInfo $file) => $file === $pick)
                ->map(fn (SplFileInfo $file) => dirname($file->getRelativePathname()));
            $notes = $others->isEmpty() ? [] : ['The zip also had '.$others->join(', ').', which '.($others->count() === 1 ? 'wasn\'t' : 'weren\'t').' installed: only the server module is used.'];

            return [$pick->getPath(), $this->name($pick->getFilenameWithoutExtension()), $notes];
        }

        if ($modules->count() > 1) {
            throw new ModuleUploadException('The zip has more than one module ('.$modules->map(fn (SplFileInfo $file) => $file->getFilenameWithoutExtension())->join(', ').'). Upload one at a time.');
        }

        $top = $php->filter(fn (SplFileInfo $file) => $file->getPath() === $root)->values();

        if ($top->count() === 1) {
            return [$root, $this->name($top->first()->getFilenameWithoutExtension()), []];
        }

        if ($php->contains(fn (SplFileInfo $file) => str_contains('/'.$file->getRelativePathname(), '/gateways/'))) {
            throw new ModuleUploadException('That is a WHMCS payment gateway module. Only server (provisioning) modules can be uploaded so far.');
        }

        throw new ModuleUploadException('Couldn\'t find a WHMCS server module in the zip. It should have modules/servers/{name}/{name}.php, or {name}/{name}.php.');
    }

    private function name(string $name): string
    {
        return preg_match(self::NAME, $name)
            ? $name
            : throw new ModuleUploadException("\"{$name}\" isn't a WHMCS module name. It should be letters, digits and underscores, like the module's folder in WHMCS.");
    }

    /**
     * Puts the new folder in place, keeping the old one until $check (which
     * runs with the new files in place) passes.
     *
     * @param  callable(): mixed  $check
     */
    private function replace(string $from, string $to, callable $check): void
    {
        File::ensureDirectoryExists(dirname($to));
        $old = is_dir($to) ? $to.'.old-'.Str::random(6) : null;

        if ($old !== null) {
            rename($to, $old);
        }

        try {
            File::copyDirectory($from, $to) || throw new ModuleUploadException("Can't write {$to}.");
            $check();
        } catch (Throwable $e) {
            File::deleteDirectory($to);
            if ($old !== null) {
                rename($old, $to);
            }

            throw $e;
        }

        if ($old !== null) {
            File::deleteDirectory($old);
        }
    }
}
