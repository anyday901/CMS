<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Support\Branding;
use App\Support\Settings;
use App\System\Version;
use App\Themes\Theme;
use App\Themes\ThemeManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Settings > Appearance: the portal theme and the white-label settings. */
class AppearanceController extends Controller
{
    public function __construct(private ThemeManager $themes) {}

    public function edit(): View
    {
        $overrides = $this->themes->path().'/'.ThemeManager::OVERRIDES.'/views';

        return view('admin.appearance.edit', [
            'themes' => $this->themes->all(),
            'active' => $this->themes->active(),
            'themesPath' => $this->themes->path(),
            'overridesPath' => $overrides,
            'overridden' => is_dir($overrides) ? collect(File::allFiles($overrides))->filter(fn ($file) => str_ends_with($file->getFilename(), '.php'))->map(fn ($file) => $file->getRelativePathname())->sort()->values() : collect(),
            'company' => (array) config('billing.company'),
            'color' => config('branding.color'),
            'adminName' => config('branding.admin_name'),
            'hidePoweredBy' => (bool) config('branding.hide_powered_by'),
            'logo' => Branding::logoUrl(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in($this->themes->all()->keys()->all())],
            'company_name' => ['required', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:1000'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'company_tax_id' => ['nullable', 'string', 'max:100'],
            'admin_name' => ['nullable', 'string', 'max:100'],
            'use_color' => ['boolean'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'hide_powered_by' => ['boolean'],
            'logo' => ['nullable', 'file', 'max:2048', 'mimes:'.implode(',', Branding::LOGO_TYPES)],
        ], ['color.regex' => 'Pick the brand colour as a hex colour, such as #0e7c6b.']);

        $theme = $this->themes->find($data['theme']);
        if (! Version::satisfies($theme?->requires)) {
            return back()->withInput()->withErrors(['theme' => "{$theme->name} needs CMS {$theme->requires} or newer."]);
        }

        $values = [
            'themes.active' => $data['theme'],
            'billing.company.name' => $data['company_name'],
            'billing.company.address' => $data['company_address'] ?? null,
            'billing.company.email' => $data['company_email'] ?? null,
            'billing.company.tax_id' => $data['company_tax_id'] ?? null,
            'branding.admin_name' => $data['admin_name'] ?? null,
            'branding.color' => $request->boolean('use_color') ? ($data['color'] ?? null) : null,
            'branding.hide_powered_by' => $request->boolean('hide_powered_by'),
        ];

        if ($request->hasFile('logo')) {
            $values['branding.logo'] = $this->storeLogo($request);
        }

        Settings::save($values);
        $this->themes->apply();
        Activity::record('Changed the appearance settings', null, collect($values)->except('billing.company.address')->all());

        return redirect()->route('admin.appearance.edit')->with('status', 'Appearance saved.');
    }

    public function removeLogo(): RedirectResponse
    {
        if ($old = Branding::logoPath()) {
            Storage::disk(Branding::DISK)->delete($old);
        }

        Settings::save(['branding.logo' => null]);
        Activity::record('Removed the logo');

        return redirect()->route('admin.appearance.edit')->with('status', 'Logo removed.');
    }

    private function storeLogo(Request $request): string
    {
        $file = $request->file('logo');
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, Branding::LOGO_TYPES, true)) {
            $extension = $file->guessExtension() ?: 'png';
        }

        if ($old = Branding::logoPath()) {
            Storage::disk(Branding::DISK)->delete($old);
        }

        return $file->storeAs('branding', 'logo-'.Str::random(8).'.'.$extension, Branding::DISK);
    }

    /** Hex colours for the colour picker when no brand colour is set. */
    public static function themeColor(Theme $theme): string
    {
        return $theme->colors['brand'] ?? '#0e7c6b';
    }
}
