<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/** White-label settings from Settings > Appearance: the company's name, logo and admin area name. */
final class Branding
{
    /** The disk the logo is kept on. */
    public const DISK = 'local';

    /** Logo types staff can upload. */
    public const LOGO_TYPES = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

    public static function companyName(): string
    {
        return (string) (config('billing.company.name') ?: config('app.name'));
    }

    /** What the admin area calls itself. */
    public static function adminName(): string
    {
        return (string) (config('branding.admin_name') ?: self::companyName());
    }

    public static function logoPath(): ?string
    {
        $path = config('branding.logo');

        return filled($path) && Storage::disk(self::DISK)->exists($path) ? (string) $path : null;
    }

    /** The logo's URL, changing whenever a new one is uploaded so browsers don't keep the old one. */
    public static function logoUrl(): ?string
    {
        $path = self::logoPath();

        return $path ? route('branding.logo', ['v' => substr(md5($path), 0, 8)]) : null;
    }

    /** The logo inline, for PDF invoices. SVG logos are left out, since the PDF renderer can't draw them reliably. */
    public static function logoDataUri(): ?string
    {
        $path = self::logoPath();

        if ($path === null || str_ends_with(strtolower($path), '.svg')) {
            return null;
        }

        $disk = Storage::disk(self::DISK);

        return 'data:'.$disk->mimeType($path).';base64,'.base64_encode((string) $disk->get($path));
    }
}
