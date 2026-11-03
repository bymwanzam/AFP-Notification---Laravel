<?php

namespace App\Idsr;

use App\Support\OrgUnits;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Fetch the weekly IDSR figures from DHIMS2 (Ghana's DHIS2) into the Weekly IDSR store.
 *
 * Sign-in is a DHIS2 personal access token (DHIMS2 → your profile → Personal access tokens). The token is sent only
 * to the DHIMS2 address set here and never to the page. It is kept in the signed-in session (encrypted), and on disk
 * only when "remember" is ticked: then encrypted with the app key in PRIVATE_DIR/dhims2.json, which is never served.
 *
 * Figures come from the DHIMS2 analytics API, summed by district and epi week, so they are as fresh as the last
 * analytics run on DHIMS2 (usually overnight). Which DHIMS2 data element fills which IDSR column is a mapping,
 * suggested from the names and kept per data set in dhims2.json.
 */
class Dhims2
{
    public const DEFAULT_URL = 'https://dhims.chimgh.org/dhims';
    public const TIMEOUT = 90;
    public const UID = '/^[A-Za-z][A-Za-z0-9]{10}$/';
    private const SESSION_KEY = 'dhims2_token';

    // Words in DHIMS2 data element (and category option) names that point to each IDSR disease and measure.
    public const DISEASE_WORDS = [
        'dengue' => 'dengue', 'yf' => 'yellow fever|\byf\b',  // before AHF: dengue / yellow fever can say haemorrhagic
        'afp' => '\bafp\b|flaccid|polio', 'ahf' => 'ha?emorrhagic|\bvhf\b|\bahf\b', 'aefi' => '\baefi\b|adverse event',
        'anthrax' => 'anthrax', 'cholera' => 'cholera',
        'shigella' => 'shigell|dysenter|diarrho?ea with blood|bloody diarrho?ea', 'guinea' => 'guinea worm|dracuncul',
        'ili' => 'influenza.like|\bili\b', 'matdeath' => 'maternal (death|mortality)', 'measles' => 'measles',
        'mening' => 'mening|\bcsm\b', 'nnt' => 'neonatal tetanus|\bnnt\b', 'plague' => 'plague', 'rabies' => 'rabies|dog bite',
        'sari' => '\bsari\b|severe acute resp', 'mpox' => '\bmpox|monkey ?pox|smallpox',
        'awd_u5' => '(watery diarrho?ea|\bawd\b).*(<\s*5|under\s*5|\bu5\b|0\s*-\s*4)',
        'awd_o5' => '(watery diarrho?ea|\bawd\b).*(>\s*5|5\s*\+|5\s*(years)? and (over|above)|over\s*5|above\s*5)',
        'bu' => 'buruli', 'covid' => 'covid', 'zika' => 'zika', 'pheic' => '\bpheic\b|public health event',
    ];

    public const MEASURE_WORDS = [['Rumour', 'rumou?r'], ['D', 'death|died|dead|fatal'], ['LC', '\blab|confirm|positive'],
        ['CS', 'case|suspect']];

    // ---------- settings ----------
    public static function configFile(): string
    {
        return rtrim(config('surveillance.private_dir'), '/\\').'/dhims2.json';
    }

    public static function load(): array
    {
        $d = is_file(self::configFile()) ? json_decode((string) file_get_contents(self::configFile()), true) : null;

        return is_array($d) ? $d : [];
    }

    public static function save(array $cfg): void
    {
        @mkdir(dirname(self::configFile()), 0700, true);
        file_put_contents(self::configFile(), json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private static function decrypt(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return Crypt::decryptString($v);
        } catch (\Throwable) {
            return null;  // made with another app key: treated as not remembered
        }
    }

    private static function remembered(): ?string
    {
        return self::decrypt(self::load()['token'] ?? null);
    }

    public static function token(): ?string
    {
        $s = session()->isStarted() ? self::decrypt(session(self::SESSION_KEY)) : null;

        return $s ?: self::remembered();
    }

    /** The settings the page may see: never the token itself. */
    public static function publicConfig(): array
    {
        $cfg = self::load();
        $t = self::token();

        return ['url' => ($cfg['url'] ?? '') ?: self::DEFAULT_URL, 'hasToken' => (bool) $t, 'tokenEnd' => $t ? substr($t, -4) : '',
            'remembered' => (bool) self::remembered(), 'user' => $t ? ($cfg['user'] ?? '') : '',
            'dataSet' => $cfg['dataSet'] ?? '', 'configFile' => 'private/'.basename(self::configFile())];
    }

    public static function cleanUrl($url): string
    {
        $url = rtrim(trim((string) ($url ?? '')), '/');
        $url = preg_replace('#/(api|dhis-web-[\w-]+)(/.*)?$#', '', $url);  // pasted from a browser tab or an API link
        if (! preg_match('#^https?://[^\s/]+#', $url)) {
            throw new InvalidArgumentException('Enter the DHIMS2 address, starting with https://');
        }

        return $url;
    }

    // ---------- talking to DHIMS2 ----------
    /** A query string; a list value repeats its key (dimension=dx:..&dimension=pe:..), as DHIS2 expects. */
    private static function query(array $params): string
    {
        $out = [];
        foreach ($params as $k => $v) {
            foreach ((array) $v as $one) {
                $out[] = rawurlencode($k).'='.strtr(rawurlencode((string) $one), ['%3A' => ':', '%3B' => ';', '%2C' => ',', '%5B' => '[', '%5D' => ']']);
            }
        }

        return implode('&', $out);
    }

    public static function call(string $path, array $params = [], ?string $url = null, ?string $tok = null): array
    {
        $base = self::cleanUrl($url ?: ((self::load()['url'] ?? '') ?: self::DEFAULT_URL));
        $tok = $tok ?: self::token();
        if (! $tok) {
            throw new InvalidArgumentException('Connect to DHIMS2 with your access token first.');
        }
        $q = self::query($params);
        try {
            $r = Http::withHeaders(['Authorization' => "ApiToken $tok", 'Accept' => 'application/json'])
                ->timeout(self::TIMEOUT)->get("$base/api/$path".($q !== '' ? "?$q" : ''));
        } catch (ConnectionException $e) {
            throw new InvalidArgumentException("Could not reach DHIMS2 at $base ({$e->getMessage()}). Check the internet connection and the address.");
        }
        if ($r->failed()) {
            $detail = (string) (($r->json() ?? [])['message'] ?? '');
            throw new InvalidArgumentException(match ($r->status()) {
                401 => 'DHIMS2 did not accept the token. It may have expired or been revoked: create a new one and connect again.',
                403 => 'Your DHIMS2 account is not allowed to read this. '.$detail,
                404 => "Not found at $base. Check the DHIMS2 address.",
                default => trim("DHIMS2 answered with error {$r->status()}. $detail"),
            });
        }
        $body = $r->body();
        if (str_starts_with(ltrim($body), '<')) {
            throw new InvalidArgumentException('DHIMS2 sent back a web page instead of data (often its login page). Check the address and the token.');
        }
        $d = json_decode($body, true);
        if (! is_array($d)) {
            throw new InvalidArgumentException('DHIMS2 sent back something that is not data. Check the address.');
        }

        return $d;
    }

    public static function connect($url, $tok, bool $remember): array
    {
        $url = self::cleanUrl($url);
        $tok = trim((string) ($tok ?? '')) ?: self::token();
        if (! $tok) {
            throw new InvalidArgumentException('Paste your DHIMS2 access token.');
        }
        $me = self::call('me', ['fields' => 'displayName,username'], $url, $tok);
        $cfg = self::load();
        $cfg['url'] = $url;
        $cfg['user'] = ($me['displayName'] ?? '') ?: ($me['username'] ?? '');
        if ($remember) {
            $cfg['token'] = Crypt::encryptString($tok);
        } else {
            unset($cfg['token']);
        }
        self::save($cfg);
        session([self::SESSION_KEY => Crypt::encryptString($tok)]);

        return self::publicConfig();
    }

    public static function forget(): array
    {
        session()->forget(self::SESSION_KEY);
        $cfg = self::load();
        unset($cfg['token']);
        self::save($cfg);

        return self::publicConfig();
    }

    /** Weekly data sets first, IDSR ones at the top. */
    public static function dataSets(): array
    {
        $sets = self::call('dataSets', ['fields' => 'id,displayName,periodType', 'paging' => 'false'])['dataSets'] ?? [];
        $rank = fn ($d) => [($d['periodType'] ?? '') !== 'Weekly', ! str_contains(strtolower($d['displayName']), 'idsr'), strtolower($d['displayName'])];
        usort($sets, fn ($a, $b) => $rank($a) <=> $rank($b));

        return array_map(fn ($d) => ['id' => $d['id'], 'name' => $d['displayName'], 'period' => $d['periodType'] ?? ''], $sets);
    }

    public static function elements($ds): array
    {
        if (! preg_match(self::UID, (string) ($ds ?? ''))) {
            throw new InvalidArgumentException('Choose a DHIMS2 data set.');
        }
        $fields = 'id,displayName,periodType,dataSetElements[dataElement[id,displayName,categoryCombo[categoryOptionCombos[id,displayName]]]]';
        $d = self::call("dataSets/$ds", ['fields' => $fields]);
        $out = [];
        foreach ($d['dataSetElements'] ?? [] as $x) {
            $de = $x['dataElement'] ?? [];
            if (empty($de['id'])) {
                continue;
            }
            $combos = [];
            foreach ($de['categoryCombo']['categoryOptionCombos'] ?? [] as $c) {
                if (strtolower($c['displayName'] ?? '') !== 'default') {
                    $combos[] = ['id' => $c['id'], 'name' => $c['displayName']];
                }
            }
            usort($combos, fn ($a, $b) => strcmp($a['name'], $b['name']));
            $out[] = ['id' => $de['id'], 'name' => $de['displayName'] ?? $de['id'], 'combos' => $combos];
        }
        usort($out, fn ($a, $b) => strcmp(strtolower($a['name']), strtolower($b['name'])));
        $cfg = self::load();
        $cfg['dataSet'] = $ds;
        self::save($cfg);
        $saved = $cfg['mappings'][$ds] ?? null;

        return ['name' => $d['displayName'] ?? $ds, 'period' => $d['periodType'] ?? '', 'elements' => $out,
            'mapping' => (object) ($saved ?? self::suggest($out)), 'suggested' => $saved === null];
    }

    // ---------- which data element fills which IDSR column ----------
    /** [disease key, measure] that a DHIMS2 name points to; either may be null. */
    public static function classify(string $name): array
    {
        $n = strtolower($name);
        $disease = null;
        foreach (self::DISEASE_WORDS as $k => $rx) {
            if (preg_match("/$rx/", $n)) {
                $disease = $k;
                break;
            }
        }
        $measure = null;
        foreach (self::MEASURE_WORDS as [$m, $rx]) {
            if (preg_match("/$rx/", $n)) {
                $measure = $m;
                break;
            }
        }

        return [$disease, $measure];
    }

    /** [column => 'element' or 'element.combo'] guessed from the names, for the user to check. */
    public static function suggest(array $els): array
    {
        $measures = array_column(Store::diseases(), 'measures', 'key');
        $cols = Store::cols();
        $found = [];
        $put = function ($disease, $measure, $item) use ($measures, $cols, &$found) {
            $col = "$disease.".($measure ?: ($measures[$disease] === ['D'] ? 'D' : 'CS'));
            if (in_array($col, $cols, true)) {
                $found[$col] ??= $item;
            }
        };
        foreach ($els as $e) {
            [$disease, $measure] = self::classify($e['name']);
            // One element whose category options are different columns (Cases / Deaths, or AWD < 5 / 5+): map each
            // option. Options that are all the same column (age groups of "AFP cases") use the element's total.
            $byCombo = array_map(fn ($c) => [$c, ...self::classify($e['name'].' '.$c['name'])], $e['combos']);
            $distinct = [];
            foreach ($byCombo as [, $d, $m]) {
                if ($d) {
                    $distinct[$d.'.'.($m ?: 'CS')] = true;
                }
            }
            if (count($distinct) > 1) {
                foreach ($byCombo as [$c, $d, $m]) {
                    if ($d) {
                        $put($d, $m, "{$e['id']}.{$c['id']}");
                    }
                }
            } elseif ($disease) {
                $put($disease, $measure, $e['id']);
            }
        }

        return $found;
    }

    public static function saveMapping($ds, $mapping): array
    {
        if (! preg_match(self::UID, (string) ($ds ?? ''))) {
            throw new InvalidArgumentException('Choose a DHIMS2 data set.');
        }
        $heads = Store::heads();
        $clean = [];
        foreach (is_array($mapping) ? $mapping : [] as $col => $item) {
            if (! isset($heads[$col]) || ! $item) {
                continue;
            }
            $parts = explode('.', (string) $item);
            if (count($parts) > 2 || array_filter($parts, fn ($p) => ! preg_match(self::UID, $p))) {
                throw new InvalidArgumentException("{$heads[$col]}: not a DHIMS2 data element.");
            }
            $clean[$col] = (string) $item;
        }
        $cfg = self::load();
        $cfg['mappings'][$ds] = (object) $clean;
        $cfg['dataSet'] = $ds;
        self::save($cfg);

        return $clean;
    }

    // ---------- fetching ----------
    /** [[item, period, org unit id, value]] and org unit names, asking for a few items at a time to keep URLs short. */
    public static function analytics(array $dx, array $periods, string $ou): array
    {
        $rows = [];
        $names = [];
        foreach (array_chunk($dx, 30) as $chunk) {
            $d = self::call('analytics', ['dimension' => ['dx:'.implode(';', $chunk), 'pe:'.implode(';', $periods), "ou:$ou"],
                'skipRounding' => 'true', 'displayProperty' => 'NAME']);
            $idx = [];
            foreach ($d['headers'] ?? [] as $k => $h) {
                $idx[$h['name']] = $k;
            }
            if (! isset($idx['dx'], $idx['pe'], $idx['ou'], $idx['value'])) {
                continue;
            }
            foreach ($d['rows'] ?? [] as $r) {
                $v = $r[$idx['value']] ?? null;
                if (is_numeric($v)) {
                    $rows[] = [$r[$idx['dx']], $r[$idx['pe']], $r[$idx['ou']], (float) $v];
                }
            }
            foreach ($d['metaData']['items'] ?? [] as $k => $v) {
                $names[$k] = $v['name'] ?? $k;
            }
        }

        return [$rows, $names];
    }

    /** Weeks $first..$last of $year from DHIMS2, as an import plan for the store (nothing is saved here). */
    public static function fetch(Store $store, $ds, $mapping, $year, $first, $last): array
    {
        $mapping = self::saveMapping($ds, $mapping);
        if (! $mapping) {
            throw new InvalidArgumentException('Map at least one IDSR column to a DHIMS2 data element first.');
        }
        [$y, $w1] = Store::checkWeek($year, $first);
        [, $w2] = Store::checkWeek($year, $last);
        if ($w2 < $w1) {
            [$w1, $w2] = [$w2, $w1];
        }
        if ($w2 - $w1 > 52) {
            throw new InvalidArgumentException('Fetch up to one year of weeks at a time.');
        }
        $periods = array_map(fn ($w) => "{$y}W$w", range($w1, $w2));
        if ($store->national) {
            // Ghana: the figures of each region (DHIMS2 adds up its districts), one row per region.
            $unit = OrgUnits::nationalUnit();
            if (! $unit['ids']) {
                throw new InvalidArgumentException('The regions have no DHIMS2 org unit ids.');
            }
            $ou = implode(';', $unit['ids']);
        } else {
            $unit = OrgUnits::regionOf($store->region);
            if (! $unit || empty($unit['id'])) {
                throw new InvalidArgumentException(($store->region ?: 'This region').' has no DHIMS2 org unit id.');
            }
            $level = self::call("organisationUnits/{$unit['id']}", ['fields' => 'level'])['level'] ?? null;
            if (! $level) {
                throw new InvalidArgumentException("DHIMS2 has no org unit {$unit['id']} for {$unit['name']}.");
            }
            $ou = "{$unit['id']};LEVEL-".((int) $level + 1);
        }

        $items = array_values(array_unique(array_values($mapping)));
        sort($items);
        [$rows, $names] = self::analytics($items, $periods, $ou);
        $problems = [];
        $notes = [];
        try {  // DHIMS2 reporting: expected, received and on-time reports, for completeness and timeliness
            $rep = array_map(fn ($m) => "$ds.$m", ['EXPECTED_REPORTS', 'ACTUAL_REPORTS', 'ACTUAL_REPORTS_ON_TIME']);
            [$repRows, $repNames] = self::analytics($rep, $periods, $ou);
            $names = $repNames + $names;
        } catch (InvalidArgumentException $e) {
            $repRows = [];
            $notes[] = 'Reporting completeness could not be read from DHIMS2: '.$e->getMessage();
        }

        $byId = [];
        foreach ($unit['ids'] ?? [] as $c => $i) {
            if ($i) {
                $byId[$i] = (string) $c;
            }
        }
        $known = array_column($store->districts, 0);
        $codes = [];
        $unmatched = [];
        $codeOf = function ($ouId) use (&$codes, &$unmatched, $byId, $known, $store, $names) {
            if (! array_key_exists($ouId, $codes)) {
                $c = $byId[$ouId] ?? null;
                $codes[$ouId] = $c && in_array($c, $known, true) ? $c : $store->districtCode($names[$ouId] ?? '');
                if (! $codes[$ouId]) {
                    $unmatched[$names[$ouId] ?? $ouId] = true;
                }
            }

            return $codes[$ouId];
        };

        $weekOf = [];
        foreach ($periods as $p) {
            $weekOf[$p] = (int) explode('W', $p)[1];
        }
        $incoming = [];
        $reported = [];
        foreach ($rows as [$item, $pe, $ouId, $value]) {
            $code = $codeOf($ouId);
            if (! $code || ! isset($weekOf[$pe])) {
                continue;
            }
            $k = Store::key($y, $weekOf[$pe]);
            $incoming[$k] ??= ['meta' => [], 'counts' => []];
            foreach ($mapping as $col => $it) {
                if ($it === $item) {
                    $incoming[$k]['counts'][$code][$col] = ($incoming[$k]['counts'][$code][$col] ?? 0) + $value;
                }
            }
            $incoming[$k]['counts'][$code] ??= [];
            $reported[$weekOf[$pe]][$code] = true;
        }
        $totals = [];
        foreach ($repRows as [$item, $pe, $ouId, $value]) {
            if (! isset($weekOf[$pe])) {
                continue;
            }
            $kind = substr($item, strrpos($item, '.') + 1);
            $w = $weekOf[$pe];
            $totals[$w][$kind] = ($totals[$w][$kind] ?? 0) + $value;
            if ($kind === 'ACTUAL_REPORTS' && $value > 0 && ($code = $codeOf($ouId))) {
                $reported[$w][$code] = true;
            }
        }

        // A district that sent its report has 0 for every mapped column DHIMS2 left empty (DHIS2 does not store zeros).
        for ($w = $w1; $w <= $w2; $w++) {
            $t = $totals[$w] ?? [];
            $sent = array_keys($reported[$w] ?? []);
            $k = Store::key($y, $w);
            if (! $sent && empty($t['ACTUAL_REPORTS'])) {
                $notes[] = sprintf('Week %03d: no data in DHIMS2 yet.', $w);
                unset($incoming[$k]);
                continue;
            }
            $incoming[$k] ??= ['meta' => [], 'counts' => []];
            foreach ($sent as $code) {
                foreach ($mapping as $col => $_) {
                    $incoming[$k]['counts'][$code][$col] = (int) round($incoming[$k]['counts'][$code][$col] ?? 0);
                }
            }
            if (! empty($t['EXPECTED_REPORTS'])) {  // DHIS2 leaves out zero rows: no on-time row means none were on time
                $incoming[$k]['meta'] = ['expected' => (int) round($t['EXPECTED_REPORTS']), 'received' => (int) round($t['ACTUAL_REPORTS'] ?? 0),
                    'ontime' => (int) round($t['ACTUAL_REPORTS_ON_TIME'] ?? 0)];
            }
        }
        if ($unmatched) {
            $u = array_keys($unmatched);
            sort($u);
            $problems[] = 'DHIMS2 org units not matched to a '.($store->national ? 'region' : 'district')
                .' (their figures are left out): '.implode(', ', $u);
        }
        $missing = count(array_diff(Store::cols(), array_keys($mapping)));
        if ($missing) {
            $notes[] = "$missing IDSR columns are not mapped to DHIMS2 and are left as they are.";
        }
        $plan = $store->planIncoming($incoming, $problems, $notes);
        $plan['source'] = ['weeks' => [$w1, $w2], 'year' => $y, 'dataSet' => $ds];

        return $plan;
    }
}
