<?php

namespace App\Support;

/**
 * Letterheads and support contacts shared by every page: the GHS logos, the organisation line and how a region is
 * named, and the support team's WhatsApp number and email.
 */
class Branding
{
    public const IMAGE_TYPES = ["\x89PNG\r\n\x1a\n" => ['.png', 'image/png'], "\xff\xd8\xff" => ['.jpg', 'image/jpeg']];

    public static function img(string $name): string
    {
        return public_path('static/core/img/'.$name);
    }

    public static function logo(): string
    {
        return self::img('logo-web.png');  // GHS emblem with the Upper East RHD mark
    }

    public static function emblem(): string
    {
        return self::img('ghs-emblem.png');
    }

    public static function rhd(): string
    {
        return self::img('rhd-mark.png');
    }

    public static function support(): array
    {
        $wa = config('surveillance.support.whatsapp');

        return ['wa_number' => $wa, 'wa_link' => 'https://wa.me/233'.ltrim($wa, '0'),
            'email' => config('surveillance.support.email')];
    }

    public static function imageType(string $body): ?array
    {
        foreach (self::IMAGE_TYPES as $magic => $type) {
            if (str_starts_with($body, $magic)) {
                return $type;
            }
        }

        return null;
    }

    /** An image as a data: URI. The pages draw their logos into exported PNGs, which only works with inline images. */
    public static function dataUri(?string $path): string
    {
        if (! $path || ! is_file($path)) {
            return '';
        }
        $body = file_get_contents($path);
        $mime = self::imageType($body)[1] ?? 'image/png';

        return "data:$mime;base64,".base64_encode($body);
    }

    public static function orgFor(array $unit): string
    {
        if (OrgUnits::isNational($unit)) {
            return 'Ghana Health Service · Disease Surveillance (National)';
        }

        return "Ghana Health Service · Regional Health Directorate, {$unit['name']} (Surveillance)";
    }

    /** "Upper East Region", or "Ghana (all regions)" for the national view. */
    public static function unitLabel(array $unit): string
    {
        return $unit['name'].(OrgUnits::isNational($unit) ? ' (all regions)' : ' Region');
    }

    /** The letterhead logo: the RHD mark belongs to Upper East, so other regions get the GHS emblem. */
    public static function logoFor(array $unit): string
    {
        return $unit['name'] === OrgUnits::DEFAULT_REGION ? self::logo() : self::emblem();
    }

    /** View data for a page's header and footer. */
    public static function letterhead(array $unit): array
    {
        return ['logo' => self::dataUri(self::logoFor($unit)), 'logo_alt' => 'Ghana Health Service · '.$unit['name'],
            'region_up' => strtoupper(self::unitLabel($unit)), 'org' => self::orgFor($unit)];
    }
}
