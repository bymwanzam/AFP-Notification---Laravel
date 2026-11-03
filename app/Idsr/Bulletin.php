<?php

namespace App\Idsr;

use App\Afp\Cases;
use App\Support\Branding;
use App\Support\OrgUnits;
use InvalidArgumentException;

/**
 * The Weekly IDSR page's data: a region's store, its AFP cases for the bulletin, and the map and SORMAS pictures on
 * page 2 of the bulletin (kept per region in DATA_DIR/idsr-images).
 */
class Bulletin
{
    public const IMAGE_SLOTS = ['map', 'sormas'];

    public static function imageDir(): string
    {
        return rtrim(config('surveillance.data_dir'), '/\\').'/idsr-images';
    }

    public static function imageFile(string $slot, string $region): ?string
    {
        foreach (Branding::IMAGE_TYPES as [$ext]) {
            $p = self::imageDir()."/idsr-$slot-$region$ext";
            if (is_file($p)) {
                return $p;
            }
        }

        return null;
    }

    /** Store (or with an empty body, remove) a region's map or SORMAS picture shown on page 2 of the bulletin. */
    public static function setImage(string $slot, string $body, string $region): void
    {
        if (! in_array($slot, self::IMAGE_SLOTS, true)) {
            throw new InvalidArgumentException('Unknown picture.');
        }
        $type = Branding::imageType($body);
        if ($body !== '' && ! $type) {
            throw new InvalidArgumentException('Choose a PNG or JPEG picture.');
        }
        if ($region === '' || ! ctype_alnum($region)) {
            throw new InvalidArgumentException('Set the region code first.');
        }
        if ($old = self::imageFile($slot, $region)) {
            unlink($old);
        }
        if ($body !== '') {
            @mkdir(self::imageDir(), 0700, true);
            file_put_contents(self::imageDir()."/idsr-$slot-$region{$type[0]}", $body);
        }
    }

    /** The bulletin district code of an AFP case: its register code, else its district name matched loosely. */
    public static function bulletinCode(array $case, array $districts): string
    {
        $code = strtoupper(trim((string) ($case['code'] ?? '')));
        $codes = array_column($districts, 0);
        if (in_array($code, $codes, true)) {
            return $code;
        }
        $b = OrgUnits::bare($case['district'] ?? '');
        foreach ($districts as [$c, $n]) {
            if (OrgUnits::bare($n) === $b) {
                return $c;
            }
        }
        [, $d] = OrgUnits::districtRegion($case['district'] ?? '');

        return $d && in_array($d[0], $codes, true) ? $d[0] : $code;
    }

    /** The page data for a region's store. */
    public static function snapshot(Store $store, bool $live = true): array
    {
        $snap = $store->snapshot();
        $unit = $store->national ? OrgUnits::nationalUnit() : OrgUnits::regionOf($store->region);
        $afp = [];
        try {  // this region's AFP cases for the bulletin's AFP counts and 60-day tracker; no names leave the register
            // (nationally every case, counted under its region's code)
            if ($unit && is_file(Cases::registerPath())) {
                foreach (Cases::regionCases(Cases::registerPath(), $unit) as $c) {
                    $row = [];
                    foreach (['epid', 'district', 'code', 'onset', 'outcome', 'report', 'week'] as $k) {
                        $row[$k] = $c[$k] ?? null;
                    }
                    $row['dcode'] = $store->national ? $c['code'] : self::bulletinCode($c, $store->districts);
                    $afp[] = $row;
                }
            }
        } catch (\Throwable $e) {
            report($e);
            $afp = [];
        }
        $images = [];
        foreach (self::IMAGE_SLOTS as $s) {
            $images[$s] = Branding::dataUri($store->code !== '' ? self::imageFile($s, $store->code) : null);
        }

        return $snap + ['generated' => date('Y-m-d\TH:i:s'), 'source' => basename($store->path), 'live' => $live,
            'national' => $store->national, 'saved' => is_file($store->path), 'regionsOnFile' => Store::regionsOnFile(),
            'appUrl' => '/', 'afp' => $afp, 'images' => $images];
    }
}
