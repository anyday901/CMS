<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serves the logo uploaded under Settings > Appearance. */
class BrandingAssetController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $logo = Branding::logoPath();
        abort_unless($logo !== null, 404);

        return Storage::disk(Branding::DISK)->response($logo, null, [
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            // An SVG opened on its own can't run scripts.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
