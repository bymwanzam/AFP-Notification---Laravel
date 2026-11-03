<?php

namespace App\Afp;

use DateTimeImmutable;

/**
 * Who entered which case, and when. The register has no column for this, so the app keeps it beside the register
 * ("AFP case entries.json"): EPID # => entry time, region and account. Only cases entered in the app are listed.
 */
class Entries
{
    public const EDIT_HOURS = 24;  // a regional account can edit or delete a case it entered for this long

    public static function path(string $xlsx): string
    {
        return dirname($xlsx).'/AFP case entries.json';
    }

    public static function load(string $xlsx): array
    {
        $f = self::path($xlsx);
        $d = is_file($f) ? json_decode(file_get_contents($f), true) : null;

        return is_array($d) ? $d : [];
    }

    private static function save(string $xlsx, array $data): void
    {
        file_put_contents(self::path($xlsx), json_encode((object) $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** $items: [[epid, region, user]] entered now. */
    public static function log(string $xlsx, array $items): void
    {
        $data = self::load($xlsx);
        $now = date('Y-m-d\TH:i:s');
        foreach ($items as [$epid, $region, $user]) {
            $data[strtoupper(trim((string) $epid))] = ['at' => $now, 'region' => $region, 'by' => $user];
        }
        self::save($xlsx, $data);
    }

    /** Keep a case's entry time when its EPID # is corrected. */
    public static function rename(string $xlsx, string $old, string $new): void
    {
        $data = self::load($xlsx);
        if (isset($data[$old]) && $new !== $old) {
            $data[$new] = $data[$old];
            unset($data[$old]);
            self::save($xlsx, $data);
        }
    }

    public static function drop(string $xlsx, string $epid): void
    {
        $data = self::load($xlsx);
        if (isset($data[$epid])) {
            unset($data[$epid]);
            self::save($xlsx, $data);
        }
    }

    /** When the edit window of a logged entry closes. */
    public static function until(array $entry): DateTimeImmutable
    {
        return (new DateTimeImmutable($entry['at']))->modify('+'.self::EDIT_HOURS.' hours');
    }
}
