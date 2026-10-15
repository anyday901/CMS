<?php

namespace App\Http\Controllers\Admin;

use App\Addons\Addon;
use App\Addons\AddonManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

/** Settings > Add-ons: install, configure, enable and remove add-on modules. */
class AddonController extends Controller
{
    public function __construct(private AddonManager $addons) {}

    public function index(): View
    {
        return view('admin.addons.index', ['addons' => $this->addons->available()->sortBy(fn (Addon $addon) => $addon->name()), 'manager' => $this->addons]);
    }

    public function install(string $key): RedirectResponse
    {
        $addon = $this->addon($key);

        try {
            $this->addons->install($key);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['addon' => $e->getMessage()]);
        }

        return redirect()->route('admin.addons.edit', $key)->with('status', "{$addon->name()} is installed. Check its settings, then enable it.");
    }

    public function edit(string $key): View
    {
        $addon = $this->addon($key);
        abort_unless($this->addons->isInstalled($key), 404);

        return view('admin.addons.edit', [
            'addon' => $addon,
            'record' => $this->addons->installed()->get($key),
            'values' => $this->addons->settings($key),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $addon = $this->addon($key);
        abort_unless($this->addons->isInstalled($key), 404);
        $saved = $this->addons->settings($key);
        $values = [];

        $rules = collect($addon->settings())->mapWithKeys(fn (array $field, string $name) => [$name => match ($field['type']) {
            'checkbox' => ['boolean'],
            'number' => ['nullable', 'numeric', ...$field['rules'] ?? []],
            'select' => ['nullable', Rule::in(array_keys($field['options'] ?? [])), ...$field['rules'] ?? []],
            default => ['nullable', 'string', 'max:5000', ...$field['rules'] ?? []],
        }])->all();
        $data = $request->validate($rules, [], collect($addon->settings())->map(fn (array $field) => $field['label'])->all());

        foreach ($addon->settings() as $name => $field) {
            $values[$name] = match ($field['type']) {
                'checkbox' => $request->boolean($name),
                // A blank secret keeps the one saved before.
                'secret' => filled($data[$name] ?? null) ? $data[$name] : ($saved[$name] ?? null),
                default => $data[$name] ?? null,
            };
        }

        $this->addons->saveSettings($key, $values);

        return redirect()->route('admin.addons.edit', $key)->with('status', 'Settings saved.');
    }

    public function enable(string $key): RedirectResponse
    {
        return $this->toggle($key, true);
    }

    public function disable(string $key): RedirectResponse
    {
        return $this->toggle($key, false);
    }

    /** Runs database changes a newer version of the add-on brings. */
    public function upgrade(string $key): RedirectResponse
    {
        $addon = $this->addon($key);

        try {
            $this->addons->upgrade($key);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['addon' => $e->getMessage()]);
        }

        return back()->with('status', "{$addon->name()} is updated to version {$addon->version()}.");
    }

    public function destroy(Request $request, string $key): RedirectResponse
    {
        $removeData = $request->boolean('remove_data');

        try {
            $this->addons->uninstall($key, $removeData);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['addon' => $e->getMessage()]);
        }

        return redirect()->route('admin.addons.index')->with('status', $removeData
            ? 'The add-on is uninstalled and its data deleted.'
            : 'The add-on is uninstalled. Its data is kept, so installing it again brings it back.');
    }

    private function toggle(string $key, bool $enabled): RedirectResponse
    {
        $addon = $this->addon($key);

        try {
            $this->addons->setEnabled($key, $enabled);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['addon' => $e->getMessage()]);
        }

        return back()->with('status', $addon->name().($enabled ? ' is enabled.' : ' is disabled. Its data is kept.'));
    }

    private function addon(string $key): Addon
    {
        return $this->addons->find($key) ?? abort(404);
    }
}
