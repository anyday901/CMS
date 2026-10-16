<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Marketplace\Manifest;
use App\Marketplace\Marketplace;
use App\Marketplace\PackageInstaller;
use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** Settings > Marketplace: browse, install, update and remove add-ons and themes. */
class MarketplaceController extends Controller
{
    public function __construct(private Marketplace $marketplace, private PackageInstaller $installer) {}

    public function index(Request $request): View
    {
        $packages = $this->marketplace->packages($request->boolean('refresh'));
        $type = $request->query('type');

        return view('admin.system.marketplace', [
            'packages' => $packages?->when(in_array($type, [Manifest::ADDON, Manifest::THEME], true), fn ($list) => $list->where('type', $type))->values(),
            'type' => $type,
            'installed' => $this->marketplace->installedVersions(),
            'marketplace' => $this->marketplace,
            'installs' => Marketplace::installsAllowed() && ! Demo::enabled(),
            'registry' => config('marketplace.registry_url'),
        ]);
    }

    public function install(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate(['type' => ['required', Rule::in([Manifest::ADDON, Manifest::THEME])], 'key' => ['required', 'string', 'max:50']]);

        try {
            $manifest = $this->marketplace->install($data['type'], $data['key']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['marketplace' => $e->getMessage()]);
        }

        return back()->with('status', $this->done($manifest));
    }

    public function upload(Request $request): RedirectResponse
    {
        $this->guard();
        $request->validate(['package' => ['required', 'file', 'mimes:zip', 'max:7680']], [], ['package' => 'package']);

        try {
            $manifest = $this->installer->install($request->file('package')->getRealPath());
        } catch (RuntimeException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return back()->with('status', $this->done($manifest));
    }

    public function destroy(string $type, string $key): RedirectResponse
    {
        $this->guard();

        try {
            $this->installer->remove($type, $key);
        } catch (RuntimeException $e) {
            return back()->withErrors(['marketplace' => $e->getMessage()]);
        }

        return back()->with('status', 'Removed.');
    }

    private function guard(): void
    {
        Demo::refuse();
        abort_unless(Marketplace::installsAllowed(), 403, 'Installing from the marketplace is turned off (MARKETPLACE_INSTALLS=false).');
    }

    private function done(array $manifest): string
    {
        return $manifest['type'] === Manifest::ADDON
            ? "{$manifest['name']} {$manifest['version']} is unpacked. Install and enable it under Add-ons (update it there if it was installed already)."
            : "{$manifest['name']} {$manifest['version']} is installed. Pick it under Appearance to use it.";
    }
}
