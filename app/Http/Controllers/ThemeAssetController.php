<?php

namespace App\Http\Controllers;

use App\Themes\ThemeManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves a theme's stylesheets, scripts, images and fonts from themes/{key}/assets. */
class ThemeAssetController extends Controller
{
    /** The only kinds of file served, by extension. */
    public const TYPES = [
        'css' => 'text/css', 'js' => 'text/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    ];

    public function __invoke(ThemeManager $themes, string $theme, string $path): BinaryFileResponse
    {
        $found = $themes->find($theme);
        $type = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        abort_if($found === null || $found->builtIn() || $type === null, 404);

        $root = realpath($found->path.'/assets');
        $file = $root ? realpath($root.'/'.$path) : false;
        abort_unless($file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);

        return response()->file($file, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
