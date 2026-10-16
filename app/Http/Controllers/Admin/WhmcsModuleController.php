<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhmcsModule;
use App\Provisioning\Whmcs\ModuleInstaller;
use App\Provisioning\Whmcs\ModuleUploadException;
use App\Provisioning\Whmcs\WhmcsModules;
use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Settings > WHMCS modules: upload WHMCS server modules, read the check of each, and turn them on. */
class WhmcsModuleController extends Controller
{
    public function __construct(private ModuleInstaller $installer, private WhmcsModules $modules) {}

    public function index(): View
    {
        return view('admin.system.whmcs-modules', [
            'modules' => WhmcsModule::orderBy('name')->get(),
            'inUse' => fn (WhmcsModule $module) => $this->modules->inUse($module),
            'uploads' => self::uploadsAllowed(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard();
        $request->validate(['module' => ['required', 'file', 'extensions:php,zip', 'max:7680']], [], ['module' => 'module']);
        $file = $request->file('module');

        try {
            $module = $this->installer->install($file->getRealPath(), $file->getClientOriginalName());
        } catch (ModuleUploadException $e) {
            return back()->withErrors(['module' => $e->getMessage()]);
        }

        return back()->with('status', $module->enabled
            ? "{$module->display_name} is updated."
            : "{$module->name} is uploaded. Read its check below, then enable it.");
    }

    public function update(Request $request, WhmcsModule $module): RedirectResponse
    {
        Demo::refuse();
        $module->update(['needs_domain' => $request->boolean('needs_domain')]);

        return back()->with('status', 'Saved.');
    }

    public function enable(WhmcsModule $module): RedirectResponse
    {
        $this->guard();

        try {
            $this->installer->enable($module);
        } catch (ModuleUploadException $e) {
            return back()->withErrors(['whmcs_module' => $e->getMessage()]);
        }

        return back()->with('status', "{$module->display_name} is enabled. Choose it on a product, and add its servers under Products > Servers.");
    }

    public function disable(WhmcsModule $module): RedirectResponse
    {
        return $this->attempt(fn () => $this->installer->disable($module), "{$module->display_name} is turned off.");
    }

    public function destroy(WhmcsModule $module): RedirectResponse
    {
        return $this->attempt(fn () => $this->installer->remove($module), "{$module->name} is removed.");
    }

    public static function uploadsAllowed(): bool
    {
        return (bool) config('provisioning.whmcs_uploads') && ! Demo::enabled();
    }

    private function attempt(callable $action, string $done): RedirectResponse
    {
        Demo::refuse();

        try {
            $action();
        } catch (ModuleUploadException $e) {
            return back()->withErrors(['whmcs_module' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }

    private function guard(): void
    {
        Demo::refuse();
        abort_unless(config('provisioning.whmcs_uploads'), 403, 'Uploading WHMCS modules is turned off (WHMCS_MODULE_UPLOADS=false).');
    }
}
