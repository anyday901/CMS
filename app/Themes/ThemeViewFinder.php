<?php

namespace App\Themes;

use Illuminate\View\FileViewFinder;

/**
 * Looks for client-facing views in the active theme (and this site's
 * overrides) before resources/views. Admin and installer views always come
 * from the app, so a broken theme can't lock staff out.
 */
class ThemeViewFinder extends FileViewFinder
{
    /** @var list<string> */
    private array $themePaths = [];

    /** Views a theme can't replace. */
    private const PROTECTED = ['admin.', 'install.', 'components.admin-layout'];

    /** @param  list<string>  $paths  most specific first */
    public function setThemePaths(array $paths): void
    {
        $this->themePaths = $paths;
        $this->flush();
    }

    /** @return list<string> */
    public function themePaths(): array
    {
        return $this->themePaths;
    }

    protected function findInPaths($name, $paths)
    {
        if ($this->themePaths !== [] && $paths === $this->paths && ! $this->isProtected($name)) {
            $paths = [...$this->themePaths, ...$paths];
        }

        return parent::findInPaths($name, $paths);
    }

    private function isProtected(string $name): bool
    {
        $name = str_replace('/', '.', $name);

        foreach (self::PROTECTED as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
