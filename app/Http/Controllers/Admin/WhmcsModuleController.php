<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhmcsModule;
use App\Provisioning\Converted\ConvertedModules;
use App\Provisioning\Whmcs\ModuleConverter;
use App\Provisioning\Whmcs\ModuleInstaller;
use App\Provisioning\Whmcs\ModuleUploadException;
use App\Provisioning\Whmcs\WhmcsModules;
use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/** Settings > WHMCS modules: upload WHMCS server modules, read the check of each, turn them on, and convert them into CMS modules. */
class WhmcsModuleController extends Controller
{
    public function __construct(private ModuleInstaller $installer, private WhmcsModules $modules, private ModuleConverter $converter, private ConvertedModules $converted) {}

    public function index(): View
    {
        return view('admin.system.whmcs-modules', [
            'modules' => WhmcsModule::orderBy('name')->get(),
            'inUse' => fn (WhmcsModule $module) => $this->modules->inUse($module),
            'converted' => collect($this->converted->manifests())->sortBy('key')->values(),
            'convertedInUse' => fn (string $key) => $this->converted->inUse($key),
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

    public function convert(WhmcsModule $module): RedirectResponse
    {
        $this->guard();

        try {
            $converted = $this->converter->convert($module);
        } catch (ModuleUploadException $e) {
            return back()->withErrors(['whmcs_module' => $e->getMessage()]);
        }

        return back()->with('status', "{$module->display_name} is now the {$converted['class']} module, and its products and servers use it. Download it to keep the code with your site.");
    }

    /** The converted module's folder as a zip. */
    public function download(string $key): BinaryFileResponse
    {
        $manifest = $this->converted->find($key) ?? abort(404);
        $zip = tempnam(sys_get_temp_dir(), 'module').'.zip';
        $archive = new ZipArchive;
        $archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach (File::allFiles($manifest['folder']) as $file) {
            $archive->addFile($file->getPathname(), $key.'/'.$file->getRelativePathname());
        }

        $archive->close();

        return response()->download($zip, "{$key}.zip")->deleteFileAfterSend();
    }

    public function destroyConverted(string $key): RedirectResponse
    {
        return $this->attempt(fn () => $this->converter->remove($key), "The converted module {$key} is removed.");
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
