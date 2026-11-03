<?php

namespace App\Support;

/**
 * Ghana's regions and districts, from the DHIS2 org unit exports in resources/data/ghana-org-units.json.
 *
 * District codes for North East and Upper East are the ones already used in the bulletins and the AFP register.
 * The others are made from the district names (first letters of the words, then the rest of the first word),
 * unique within the region.
 */
class OrgUnits
{
    public const DEFAULT_REGION = 'Upper East';  // the AFP register's own region
    public const NATIONAL = 'Ghana';             // the national view: every region, added up

    public const REGION_CODES = [
        'Ahafo' => 'AHR', 'Ashanti' => 'ASR', 'Bono' => 'BR', 'Bono East' => 'BER', 'Central' => 'CR', 'Eastern' => 'ER',
        'Greater Accra' => 'GAR', 'North East' => 'NER', 'Northern' => 'NR', 'Oti' => 'OR', 'Savannah' => 'SR',
        'Upper East' => 'UER', 'Upper West' => 'UWR', 'Volta' => 'VR', 'Western' => 'WR', 'Western North' => 'WNR',
    ];

    // Codes already in use, by district name; other spellings seen on forms and in DHIS2 are listed too.
    public const FIXED_CODES = [
        'Bunkpurugu Nakpayili' => 'BND', 'Bunkpurugu-Nyankpanduri' => 'BND', 'Bunkpurugu Nankpanduri' => 'BND',
        'Chereponi' => 'CHD', 'Cheriponi' => 'CHD', 'East Mamprusi' => 'EMM', 'Mamprugu Moagduri' => 'MMD',
        'West Mamprusi' => 'WMM', 'Yunyoo Nasuan' => 'YND',
        'Bawku Municipal' => 'BAW', 'Bawku Muncipal' => 'BAW', 'Bawku West' => 'BKW', 'Binduri' => 'BID', 'Bolgatanga East' => 'BOE',
        'Bolgatanga Municipal' => 'BOG', 'Bongo' => 'BON', 'Builsa North' => 'BUI', 'Builsa South' => 'BUS', 'Garu' => 'GAR',
        'Kassena Nankana East' => 'KND', 'Kassena-Nankana Municipal' => 'KND', 'Kassena Nankana West' => 'KNW',
        'Nabdam' => 'NAB', 'Pusiga' => 'PUS', 'Talensi' => 'TAL', 'Tempane' => 'TEP',
    ];

    // Corrections to the DHIS2 export, applied every time it is loaded: districts missing from the export, old
    // names and duplicates of current districts, and misspellings.
    public const CORRECTIONS = [
        'Ashanti' => ['add' => ['Adansi Asokwa', 'Adansi North', 'Adansi South', 'Kwadaso Municipal', 'Oforikrom Municipal',
            'Old Tafo Municipal', 'Suame Municipal'],
            'rename' => ['Atwima Nwabiagya South' => 'Atwima Nwabiagya Municipal']],
        'Bono' => ['rename' => ['Suinyani Municipal' => 'Sunyani Municipal', 'Suinyani East' => 'Sunyani West Municipal']],
        'Central' => ['remove' => ['Hemang Lower Denkyira']],
        'Eastern' => ['add' => ['Asuogyaman'], 'remove' => ['Afram Plains North', 'Afram Plains South']],
        'North East' => ['rename' => ['Bunkpurugu Nakpayili' => 'Bunkpurugu-Nyankpanduri']],
        'Northern' => ['add' => ['Tatale Sanguli']],
        'Upper East' => ['rename' => ['Kassena Nankana East' => 'Kassena-Nankana Municipal']],
        'Volta' => ['add' => ['Adaklu']],
        'Western' => ['remove' => ['Amenfi East']],
        'Western North' => ['add' => ['Aowin Municipal', 'Bibiani Anhwiaso Bekwai Municipal']],
    ];

    // The region part of AFP EPID numbers (GHA-UPE-BAW-26-001).
    public const EPID_REGION = [
        'Ahafo' => 'AHA', 'Ashanti' => 'ASH', 'Bono' => 'BON', 'Bono East' => 'BOE', 'Central' => 'CEN', 'Eastern' => 'EAS',
        'Greater Accra' => 'GAR', 'North East' => 'NOE', 'Northern' => 'NOR', 'Oti' => 'OTI', 'Savannah' => 'SAV',
        'Upper East' => 'UPE', 'Upper West' => 'UPW', 'Volta' => 'VOL', 'Western' => 'WES', 'Western North' => 'WEN',
    ];

    private static ?array $units = null;
    private static ?array $fixed = null;

    /** Name without spaces, punctuation or a Municipal / Metropolitan ending, for matching spellings. */
    public static function bare($name): string
    {
        $n = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($name ?? '')));
        $s = preg_replace('/(municipal(ity)?|metropolitan|metro|district)(assembly)?$/', '', $n);

        return $s !== '' ? $s : $n;
    }

    public static function fixed(): array
    {
        if (self::$fixed === null) {
            self::$fixed = [];
            foreach (self::FIXED_CODES as $n => $c) {
                self::$fixed[self::bare($n)] = $c;
            }
        }

        return self::$fixed;
    }

    /** A 3-letter code for a district, not in $used. */
    public static function makeCode(string $name, array $used): string
    {
        preg_match_all('/[A-Za-z]+/', preg_replace('/\b(Municipal|Metropolitan)\b/', '', $name), $m);
        $words = $m[0] ?: ['X'];
        $base = strtoupper(implode('', array_map(fn ($w) => $w[0], $words)).substr($words[0], 1));
        $tries = [substr($base, 0, 3)];
        $len = strlen($base);
        for ($i = 1; $i < $len; $i++) {
            for ($j = $i + 1; $j < $len; $j++) {
                $tries[] = $base[0].$base[$i].$base[$j];
            }
        }
        for ($k = 1; $k < 100; $k++) {
            $tries[] = substr($base, 0, 2).$k;
        }
        foreach ($tries as $c) {
            if (strlen($c) === 3 && ! in_array($c, $used, true)) {
                return $c;
            }
        }
        throw new \RuntimeException("No code left for $name.");
    }

    /** [{code, name, id, epid, districts: [[code, name]], ids: {code: DHIS2 id}}] for every region, corrected. */
    public static function all(): array
    {
        if (self::$units !== null) {
            return self::$units;
        }
        $source = resource_path('data/ghana-org-units.json');
        if (! is_file($source)) {
            return self::$units = [];
        }
        $out = [];
        foreach (json_decode(file_get_contents($source), true) as $r) {
            $fix = self::CORRECTIONS[$r['name']] ?? [];
            $rename = $fix['rename'] ?? [];
            $remove = $fix['remove'] ?? [];
            $items = [];
            foreach ($r['districts'] as $d) {
                if (! in_array($d['name'], $remove, true)) {
                    $items[] = ['id' => $d['id'] ?? null, 'name' => $rename[$d['name']] ?? $d['name']];
                }
            }
            $have = array_map(fn ($d) => self::bare($d['name']), $items);
            foreach ($fix['add'] ?? [] as $n) {
                if (! in_array(self::bare($n), $have, true)) {
                    $items[] = ['id' => null, 'name' => $n];
                }
            }
            usort($items, fn ($a, $b) => strcmp($a['name'], $b['name']));
            $fixed = array_map(fn ($d) => self::fixed()[self::bare($d['name'])] ?? null, $items);
            $used = array_values(array_filter($fixed));
            $districts = [];
            $ids = [];
            foreach ($items as $i => $d) {
                $c = $fixed[$i];
                if (! $c) {
                    $c = self::makeCode($d['name'], $used);
                    $used[] = $c;
                }
                $districts[] = [$c, $d['name']];
                $ids[$c] = $d['id'];
            }
            $out[] = ['code' => self::REGION_CODES[$r['name']] ?? self::makeCode($r['name'], []), 'name' => $r['name'],
                'id' => $r['id'] ?? null, 'epid' => self::EPID_REGION[$r['name']] ?? strtoupper(substr($r['name'], 0, 3)),
                'districts' => $districts, 'ids' => $ids];
        }

        return self::$units = $out;
    }

    /** The region called $name ('Upper East' or 'Upper East Region'), or null. */
    public static function regionOf($name): ?array
    {
        $b = self::bare(preg_replace('/\s*region$/i', '', (string) ($name ?? '')));
        foreach (self::all() as $r) {
            if (self::bare($r['name']) === $b) {
                return $r;
            }
        }

        return null;
    }

    /** The region used when no account says otherwise: the AFP register's own (Upper East). */
    public static function currentRegion(): ?array
    {
        return self::regionOf(self::DEFAULT_REGION);
    }

    /** Ghana as one unit whose "districts" are the regions. Only national and support accounts see it. */
    public static function nationalUnit(): array
    {
        $ids = [];
        foreach (self::all() as $r) {
            if (! empty($r['id'])) {
                $ids[$r['code']] = $r['id'];
            }
        }

        return ['name' => self::NATIONAL, 'code' => 'GHA', 'id' => null, 'epid' => 'GHA', 'national' => true,
            'ids' => $ids, 'districts' => array_map(fn ($r) => [$r['code'], $r['name']], self::all())];
    }

    public static function isNational(?array $unit): bool
    {
        return (bool) ($unit['national'] ?? false);
    }

    /** The region of a case's reporting district; a district that can't be placed stays with Upper East. */
    public static function caseRegion($district): ?array
    {
        return self::districtRegion($district)[0] ?? self::regionOf(self::DEFAULT_REGION);
    }

    /** [region, [code, name]] of the district called $name, matching spellings loosely, or [null, null]. */
    public static function districtRegion($name): array
    {
        $b = self::bare($name);
        $fixed = self::fixed()[$b] ?? null;
        foreach (self::all() as $r) {
            foreach ($r['districts'] as $d) {
                if (self::bare($d[1]) === $b || ($fixed === $d[0] && in_array($r['name'], ['North East', 'Upper East'], true))) {
                    return [$r, $d];
                }
            }
        }

        return [null, null];
    }
}
