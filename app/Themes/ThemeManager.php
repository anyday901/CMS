<?php

namespace App\Themes;

use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

/**
 * The portal themes: the built-in Harbor and the folders under themes/. The
 * active one (Settings > Appearance) recolours the site, adds its stylesheet
 * and replaces client-facing views; themes/overrides/views replaces views on
 * top of any theme.
 */
class ThemeManager
{
    public const DEFAULT = 'harbor';

    /** Folder under themes/ for this site's own template overrides. */
    public const OVERRIDES = 'overrides';

    /** How much of the colour each shade has: mixed with white below 600, with black above. */
    private const SHADES = [50 => 8, 100 => 16, 200 => 30, 300 => 48, 400 => 70, 500 => 86, 600 => 100, 700 => 82, 800 => 66, 900 => 54, 950 => 34];

    /** @var Collection<string, Theme>|null */
    private ?Collection $themes = null;

    public function path(): string
    {
        return rtrim((string) config('themes.path'), '/');
    }

    /** @return Collection<string, Theme> Harbor first, then the installed ones by name */
    public function all(): Collection
    {
        if ($this->themes === null) {
            $folders = is_dir($this->path()) ? File::directories($this->path()) : [];

            $this->themes = collect($folders)
                ->reject(fn (string $folder) => basename($folder) === self::OVERRIDES)
                ->map(fn (string $folder) => Theme::fromFolder($folder))
                ->filter()
                ->reject(fn (Theme $theme) => $theme->key === self::DEFAULT)
                ->sortBy(fn (Theme $theme) => strtolower($theme->name))
                ->keyBy(fn (Theme $theme) => $theme->key)
                ->prepend(self::harbor(), self::DEFAULT);
        }

        return $this->themes;
    }

    public function find(string $key): ?Theme
    {
        return $this->all()->get($key);
    }

    /** The theme in use; Harbor when the saved one has been removed. */
    public function active(): Theme
    {
        return $this->find((string) config('themes.active')) ?? self::harbor();
    }

    public function activate(string $key): void
    {
        Settings::save(['themes.active' => $key]);
        $this->apply();
    }

    public function forget(): void
    {
        $this->themes = null;
    }

    /** Points the view finder at the active theme and the overrides. Called at boot and after a change. */
    public function apply(): void
    {
        $finder = View::getFinder();

        if (! $finder instanceof ThemeViewFinder) {
            return;
        }

        $finder->setThemePaths(array_values(array_filter([
            is_dir($this->path().'/'.self::OVERRIDES.'/views') ? $this->path().'/'.self::OVERRIDES.'/views' : null,
            $this->active()->viewsPath(),
        ])));
    }

    /** The brand colour in use: the white-label one, else the theme's, else Harbor's. */
    public function brandColor(): ?string
    {
        $branding = (string) config('branding.color');

        return Theme::isColor($branding) ? $branding : ($this->active()->colors['brand'] ?? null);
    }

    /**
     * CSS that recolours the Tailwind brand and ink shades from one colour each.
     * Empty when Harbor's own colours are used.
     */
    public function css(): string
    {
        $colors = array_filter(['brand' => $this->brandColor(), 'ink' => $this->active()->colors['ink'] ?? null]);

        if ($colors === []) {
            return '';
        }

        $lines = [];
        foreach ($colors as $name => $color) {
            foreach (self::SHADES as $shade => $amount) {
                $mix = match (true) {
                    $shade === 600 => $color,
                    $shade < 600 => "color-mix(in oklab, {$color} {$amount}%, white)",
                    default => "color-mix(in oklab, {$color} {$amount}%, black)",
                };
                $lines[] = "--color-{$name}-{$shade}: {$mix};";
            }
        }

        return ':root{'.implode('', $lines).'}';
    }

    public static function harbor(): Theme
    {
        return new Theme(
            key: self::DEFAULT,
            name: 'Harbor',
            description: 'The built-in theme: sea green and warm sand, with rounded panels.',
            version: '',
            author: 'CMS',
        );
    }
}
