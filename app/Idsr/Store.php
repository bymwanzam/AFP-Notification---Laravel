<?php

namespace App\Idsr;

use App\Afp\Register;
use App\Afp\Text;
use App\Afp\Xl;
use App\Support\OrgUnits;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Weekly IDSR: the regional weekly summary of priority diseases by district.
 *
 * Every week is kept in `IDSR weekly data.xlsx`, one row per district per week, so weeks add up into the
 * year-to-date tables of the bulletin. Filled-in official weekly forms (IDSR Summary Regional Weekly Reporting Form)
 * or plain .xlsx/.csv tables can be imported, and any week can be written back out as the official form.
 *
 * Weeks are keyed "year-week" (2026-43); a week's grid is [district code => [column => number]].
 */
class Store
{
    // The four tables of the official form, left to right from column C. Each disease is
    // [key, name, short name used in column headers, measures, pattern its heading on the form matches].
    public const BLOCKS = [
        [['afp', 'Acute flaccid paralysis (polio)', 'AFP', 'CS D LC', '/flaccid/'],
            ['ahf', 'Acute haemorrhagic fever', 'AHF', 'CS D LC', '/haemorrhagic/'],
            ['aefi', 'Adverse events following immunisation', 'AEFI', 'CS D', '/aefi/'],
            ['anthrax', 'Anthrax', 'Anthrax', 'CS D LC', '/anthrax/'],
            ['cholera', 'Cholera', 'Cholera', 'CS D LC', '/cholera/'],
            ['dengue', 'Dengue fever', 'Dengue', 'CS D LC', '/dengue/']],
        [['shigella', 'Diarrhoea with blood (shigellosis)', 'Shigellosis', 'CS D LC', '/blood/'],
            ['guinea', 'Dracunculiasis (guinea worm)', 'Guinea worm', 'Rumour CS D LC', '/dracuncul/'],
            ['ili', 'Influenza-like illness', 'ILI', 'CS D LC', '/influenza/'],
            ['matdeath', 'Maternal deaths', 'Maternal deaths', 'D', '/maternal/'],
            ['measles', 'Measles', 'Measles', 'CS D LC', '/measles/'],
            ['mening', 'Meningitis', 'CSM', 'CS D LC', '/meningitis/']],
        [['nnt', 'Neonatal tetanus', 'NNT', 'CS D', '/tetanus/'],
            ['plague', 'Plague', 'Plague', 'CS D LC', '/plague/'],
            ['rabies', 'Rabies (human) / dog bite', 'Dog bite', 'CS D LC', '/rabies/'],
            ['sari', 'Severe acute respiratory infection', 'SARI', 'CS D LC', '/sari/'],
            ['mpox', 'Mpox (smallpox)', 'Mpox', 'CS D LC', '/mpox/'],
            ['yf', 'Yellow fever', 'YF', 'CS D LC', '/yellow/']],
        [['awd_u5', 'Acute watery diarrhoea under 5 years', 'AWD U5', 'CS D', '/<\s*5/'],
            ['awd_o5', 'Acute watery diarrhoea 5 years and over', 'AWD 5+', 'CS D LC', '/>\s*5/'],
            ['bu', 'Buruli ulcer', 'BU', 'CS D LC', '/buruli/'],
            ['covid', 'COVID-19', 'COVID-19', 'CS D LC', '/covid/'],
            ['zika', 'Zika virus disease', 'Zika', 'CS D LC', '/zika/'],
            ['pheic', 'Public health event of international or national concern', 'PHEIC', 'CS D LC', '/concern/']],
    ];

    public const MEASURES = ['CS' => 'Suspected cases', 'D' => 'Deaths', 'LC' => 'Lab confirmed', 'Rumour' => 'Rumours'];

    public const WEEK_FIELDS = [  // [key, column header in the store, kind]
        ['start', 'Week beginning', 'date'], ['end', 'Week ending', 'date'],
        ['expected', 'Reports expected', 'int'], ['received', 'Reports received', 'int'], ['ontime', 'Reports on time', 'int'],
        ['epi', 'Epidemiological summary', 'text'], ['comments', 'Comments', 'text'],
        ['decisions', 'Decisions and actions taken', 'text'], ['recommendations', 'Recommendations', 'text'],
        ['highlights', 'Extra bulletin highlights', 'text'],
        ['reporter', 'Reported by', 'text'], ['phone', 'Telephone', 'text'],
        ['filled', 'Date form filled', 'date'], ['submitted', 'Date submitted', 'date'],
        ['published', 'Bulletin publication date', 'date'],
    ];

    public string $path;
    public string $region = '';
    public string $code = '';
    public bool $national = false;  // Ghana's store: its rows are the regions
    public array $districts = [];   // [[code, name]], in report order
    public array $board = [];       // [[name, role]]
    public array $actions = [];     // standard public health actions
    public array $weeks = [];       // "y-w" => details
    public array $counts = [];      // "y-w" => [district code => [column => number]]

    private static ?array $diseases = null;

    // ---------- the diseases and columns ----------
    /** [{key, name, short, measures, block}] */
    public static function diseases(): array
    {
        if (self::$diseases === null) {
            self::$diseases = [];
            foreach (self::BLOCKS as $i => $block) {
                foreach ($block as [$k, $n, $s, $m]) {
                    self::$diseases[] = ['key' => $k, 'name' => $n, 'short' => $s, 'measures' => explode(' ', $m), 'block' => $i];
                }
            }
        }

        return self::$diseases;
    }

    /** Every count column ("afp.CS"), in form order. */
    public static function cols(): array
    {
        $out = [];
        foreach (self::diseases() as $d) {
            foreach ($d['measures'] as $m) {
                $out[] = "{$d['key']}.$m";
            }
        }

        return $out;
    }

    /** Column => its header in the store ("AFP CS"). */
    public static function heads(): array
    {
        $out = [];
        foreach (self::diseases() as $d) {
            foreach ($d['measures'] as $m) {
                $out["{$d['key']}.$m"] = "{$d['short']} $m";
            }
        }

        return $out;
    }

    private static function weekField(string $key): ?array
    {
        foreach (self::WEEK_FIELDS as $f) {
            if ($f[0] === $key) {
                return $f;
            }
        }

        return null;
    }

    // ---------- small helpers ----------
    /** A cell value as text: whole numbers without ".0", dates as yyyy-mm-dd. */
    public static function s($v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_float($v) && floor($v) == $v && abs($v) < PHP_INT_MAX) {
            return (string) (int) $v;
        }
        if (is_bool($v)) {
            return $v ? 'True' : 'False';
        }

        return (string) $v;
    }

    public static function norm($v): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(self::s($v)));
    }

    public static function text($v): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::s($v)));
    }

    private static function blankText($v): bool
    {
        return is_string($v) && in_array(trim($v), ['', '-', '–'], true);
    }

    /** Whole number >= 0, or null for a blank cell. */
    public static function toInt($v, string $what = 'value'): ?int
    {
        if ($v === null || self::blankText($v)) {
            return null;
        }
        if (is_bool($v)) {
            throw new InvalidArgumentException("$what: ".($v ? 'True' : 'False').' is not a number');
        }
        if ((is_int($v) || is_float($v)) && floor($v) == $v && $v >= 0) {
            return (int) $v;
        }
        if (is_string($v) && preg_match('/^\s*\d+(\.0+)?\s*$/', $v)) {
            return (int) $v;
        }
        throw new InvalidArgumentException("$what: “".self::s($v).'” is not a whole number');
    }

    public static function safeInt($v): ?int
    {
        try {
            return self::toInt($v);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** A date from a cell or text, as YYYY-MM-DD, or '' when there is none. */
    public static function iso($v): string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        $t = self::text($v);
        $d = $t !== '' ? Text::parseDate($t) : null;

        return $d ? $d->format('Y-m-d') : '';
    }

    private static function dmy(string $iso): string
    {
        return $iso !== '' ? (new DateTimeImmutable($iso))->format('d-m-Y') : '';
    }

    public static function weekStart(int $y, int $w): DateTimeImmutable
    {
        return (new DateTimeImmutable('today'))->setISODate($y, $w, 1);
    }

    public static function weekDates(int $y, int $w): array
    {
        $start = self::weekStart($y, $w);

        return [$start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')];
    }

    private static function weeksIn(int $y): int
    {
        return (int) (new DateTimeImmutable("$y-12-28"))->format('W');
    }

    /** [year, week] checked; throws with what to fix. */
    public static function checkWeek($year, $week): array
    {
        $y = self::toInt($year, 'Year');
        $w = self::toInt($week, 'Week');
        if (! $y || $y < 2000 || $y > 2100) {
            throw new InvalidArgumentException('Enter the year, for example 2026.');
        }
        $max = self::weeksIn($y);
        if (! $w || $w < 1 || $w > $max) {
            throw new InvalidArgumentException("Week must be between 1 and $max for $y.");
        }

        return [$y, $w];
    }

    public static function key(int $y, int $w): string
    {
        return "$y-$w";
    }

    /** [year, week] of a "y-w" key. */
    public static function yw(string $key): array
    {
        return array_map('intval', explode('-', $key, 2));
    }

    /** Week keys in date order. */
    public function weekKeys(): array
    {
        $keys = array_keys($this->weeks);
        usort($keys, fn ($a, $b) => self::yw($a) <=> self::yw($b));

        return $keys;
    }

    /** Week details from a form or the entry page, checked and in stored form. Unknown keys are ignored. */
    public static function cleanMeta(array $raw): array
    {
        $meta = [];
        $errors = [];
        foreach ($raw as $k => $v) {
            $f = self::weekField((string) $k);
            if (! $f) {
                continue;
            }
            [, $label, $kind] = $f;
            if ($kind === 'int') {
                try {
                    $meta[$k] = self::toInt($v, $label);
                } catch (InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            } elseif ($kind === 'date') {
                $meta[$k] = self::iso($v);
                if (self::text($v) !== '' && $meta[$k] === '') {
                    $errors[] = "$label: “".self::s($v).'” is not a date (use dd/mm/yyyy)';
                }
            } else {
                $meta[$k] = $v !== null ? mb_substr(trim(self::s($v)), 0, 4000) : '';
            }
        }
        if ($errors) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }

        return $meta;
    }

    public static function checkReports(array $meta): void
    {
        [$e, $r, $t] = [$meta['expected'] ?? null, $meta['received'] ?? null, $meta['ontime'] ?? null];
        if ($e !== null && $r !== null && $r > $e) {
            throw new InvalidArgumentException("Reports received can't be more than reports expected.");
        }
        if ($r !== null && $t !== null && $t > $r) {
            throw new InvalidArgumentException("Reports on time can't be more than reports received.");
        }
    }

    private static function filled($v): bool
    {
        return $v !== null && $v !== '';
    }

    // ---------- reading and writing the store ----------
    public function __construct(string $path)
    {
        $this->path = $path;
        if (is_file($path)) {
            $this->load();
        }
    }

    public function names(): array
    {
        $out = [];
        foreach ($this->districts as [$c, $n]) {
            $out[$c] = $n;
        }

        return $out;
    }

    private function load(): void
    {
        $wb = IOFactory::createReader('Xlsx')->setReadDataOnly(false)->load($this->path);
        try {
            $rows = function (string $name) use ($wb) {
                $ws = $wb->getSheetByName($name);

                return $ws ? Xl::rows($ws, null, true) : [];
            };
            $settings = [];
            foreach (array_slice($rows('Settings'), 1) as $r) {
                if (count($r) > 1 && self::filled($r[0])) {
                    $settings[self::norm($r[0])] = $r[1];
                }
            }
            $this->region = self::text($settings['region'] ?? null);
            $this->code = strtoupper(self::text($settings['regioncode'] ?? null));
            foreach (array_slice($rows('Districts'), 1) as $r) {
                if (count($r) > 1 && self::filled($r[0]) && self::filled($r[1])) {
                    $this->districts[] = [strtoupper(self::text($r[0])), self::text($r[1])];
                }
            }
            foreach (array_slice($rows('Editorial board'), 1) as $r) {
                if ($r && self::filled($r[0])) {
                    $this->board[] = [self::text($r[0]), self::text($r[1] ?? null)];
                }
            }
            foreach (array_slice($rows('Standard actions'), 1) as $r) {
                if ($r && self::filled($r[0])) {
                    $this->actions[] = self::text($r[0]);
                }
            }
            $weeks = $rows('Weeks');
            if ($weeks) {
                $idx = array_flip(array_map([self::class, 'norm'], $weeks[0]));
                $get = fn ($r, $h) => isset($idx[self::norm($h)]) ? ($r[$idx[self::norm($h)]] ?? null) : null;
                foreach (array_slice($weeks, 1) as $r) {
                    [$y, $w] = [self::safeInt($get($r, 'Year')), self::safeInt($get($r, 'Week'))];
                    if ($y && $w) {
                        $meta = [];
                        foreach (self::WEEK_FIELDS as [$k, $h, $kind]) {
                            $v = $get($r, $h);
                            $meta[$k] = $kind === 'date' ? self::iso($v) : ($kind === 'int' ? self::safeInt($v) : ($v === null ? '' : trim(self::s($v))));
                        }
                        $this->weeks[self::key($y, $w)] = $meta;
                    }
                }
            }
            $data = $rows('Weekly data');
            if ($data) {
                $idx = array_flip(array_map([self::class, 'norm'], $data[0]));
                $cols = [];
                foreach (self::heads() as $c => $h) {
                    if (isset($idx[self::norm($h)])) {
                        $cols[$c] = $idx[self::norm($h)];
                    }
                }
                [$iy, $iw, $ic] = [$idx['year'] ?? null, $idx['week'] ?? null, $idx['districtcode'] ?? null];
                foreach (array_slice($data, 1) as $r) {
                    if ($iy === null || $iw === null || $ic === null) {
                        continue;
                    }
                    [$y, $w, $code] = [self::safeInt($r[$iy] ?? null), self::safeInt($r[$iw] ?? null), strtoupper(self::text($r[$ic] ?? null))];
                    if ($y && $w && $code !== '') {
                        $vals = [];
                        foreach ($cols as $c => $i) {
                            if (($v = self::safeInt($r[$i] ?? null)) !== null) {
                                $vals[$c] = $v;
                            }
                        }
                        $this->counts[self::key($y, $w)][$code] = $vals;
                        $this->weeks[self::key($y, $w)] ??= [];
                    }
                }
            }
        } finally {
            $wb->disconnectWorksheets();
        }
    }

    /** A value into a cell: text always as text (never a formula), dates as dates. */
    private static function put(Worksheet $ws, int $col, int $row, $v): void
    {
        $cell = $ws->getCell([$col, $row]);
        if ($v === null || $v === '') {
            $cell->setValue(null);
        } elseif (is_string($v)) {
            $cell->setValueExplicit($v, DataType::TYPE_STRING);
        } else {
            $cell->setValue(Xl::toCell($v));
        }
    }

    public function save(): void
    {
        $wb = new Spreadsheet;
        $wb->removeSheetByIndex(0);
        $sheet = function (string $title, array $header, array $rows, array $widths, string $freeze = 'A2') use ($wb) {
            $ws = $wb->createSheet();
            $ws->setTitle($title);
            foreach ($header as $i => $h) {
                self::put($ws, $i + 1, 1, $h);
            }
            $last = Coordinate::stringFromColumnIndex(count($header));
            $style = $ws->getStyle("A1:{$last}1");
            $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F3864');
            $style->getAlignment()->setWrapText(true)->setVertical('center');
            foreach ($rows as $n => $r) {
                foreach (array_values($r) as $i => $v) {
                    self::put($ws, $i + 1, $n + 2, $v);
                }
            }
            foreach ($widths as $i => $w) {
                $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
            }
            $ws->freezePane($freeze);

            return $ws;
        };

        $order = array_flip(array_column($this->districts, 0));
        $names = $this->names();
        $keys = $this->weekKeys();
        $cols = self::cols();
        $heads = self::heads();
        $data = [];
        foreach ($keys as $k) {
            [$y, $w] = self::yw($k);
            $grid = $this->counts[$k] ?? [];
            uksort($grid, fn ($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));
            foreach ($grid as $code => $vals) {
                $data[] = array_merge([$y, $w, (string) $code, $names[$code] ?? (string) $code], array_map(fn ($c) => $vals[$c] ?? null, $cols));
            }
        }
        $sheet('Weekly data', array_merge(['Year', 'Week', 'District code', 'District'], array_map(fn ($c) => $heads[$c], $cols)),
            $data, array_merge([7, 7, 9, 26], array_fill(0, count($cols), 10)), 'E2');

        $weekRows = [];
        foreach ($keys as $k) {
            [$y, $w] = self::yw($k);
            $row = [$y, $w];
            foreach (self::WEEK_FIELDS as [$f, , $kind]) {
                $v = $this->weeks[$k][$f] ?? null;
                $row[] = $kind === 'date' && $v ? new DateTimeImmutable($v) : $v;
            }
            $weekRows[] = $row;
        }
        $ws = $sheet('Weeks', array_merge(['Year', 'Week'], array_column(self::WEEK_FIELDS, 1)), $weekRows,
            array_merge([7, 7], array_map(fn ($f) => $f[2] !== 'text' ? 14 : 40, self::WEEK_FIELDS)), 'C2');
        foreach (self::WEEK_FIELDS as $i => [, , $kind]) {
            if ($kind === 'date' && $weekRows) {
                $l = Coordinate::stringFromColumnIndex($i + 3);
                $ws->getStyle("{$l}2:$l".(count($weekRows) + 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            }
        }
        $sheet('Districts', ['Code', 'District'], $this->districts, [10, 32]);
        $sheet('Settings', ['Setting', 'Value'], [['Region', $this->region], ['Region code', $this->code]], [16, 40]);
        $sheet('Editorial board', ['Name', 'Role'], $this->board, [40, 20]);
        $sheet('Standard actions', ['Public health action'], array_map(fn ($a) => [$a], $this->actions), [120]);
        $wb->setActiveSheetIndex(0);
        $body = Register::bytes($wb);
        if (is_file($this->path)) {
            copy($this->path, preg_replace('/\.xlsx$/i', '', $this->path).'.bak.xlsx');
        }
        @mkdir(dirname($this->path), 0700, true);
        file_put_contents($this->path, $body, LOCK_EX);
    }

    // ---------- districts ----------
    /** Code of the district named or coded $label; close spellings match too. With $add, unknown names are added. */
    public function districtCode($label, bool $add = false): ?string
    {
        if ($this->national) {  // Ghana's rows are regions: "Upper East Region" is Upper East
            $label = preg_replace('/\s*region$/i', '', self::text($label));
        }
        $n = self::norm($label);
        $b = OrgUnits::bare(self::s($label));
        if ($n === '') {
            return null;
        }
        foreach ($this->districts as [$c, $name]) {
            if ($n === self::norm($c) || $b === OrgUnits::bare($name)) {
                return $c;
            }
        }
        // Spelling variants (Cheriponi / Chereponi) only: same start and end, so East and West Mamprusi stay apart.
        $close = [];
        foreach ($this->districts as [$c, $name]) {
            $bn = OrgUnits::bare($name);
            similar_text($b, $bn, $pct);
            if (substr($b, 0, 4) === substr($bn, 0, 4) && substr($b, -3) === substr($bn, -3) && $pct >= 85) {
                $close[] = $c;
            }
        }
        if (count($close) === 1) {
            return $close[0];
        }
        // Another known name of a district already set up (Bunkpurugu Nankpanduri / Nakpayili).
        $used = array_column($this->districts, 0);
        $code = self::knownCodes()[$b] ?? null;
        if ($code && in_array($code, $used, true)) {
            return $code;
        }
        if (! $add) {
            return null;
        }
        if (! $code) {
            $code = OrgUnits::makeCode(self::s($label), $used);
        }
        $name = self::text($label);
        $this->districts[] = [$code, $name === strtoupper($name) ? ucwords(strtolower($name)) : $name];

        return $code;
    }

    /** Bare district name => code, for districts first seen on an uploaded form. */
    private static function knownCodes(): array
    {
        $out = OrgUnits::fixed();
        foreach (OrgUnits::all() as $r) {
            foreach ($r['districts'] as [$c, $n]) {
                $out[OrgUnits::bare($n)] = $c;
            }
        }

        return $out;
    }

    // ---------- weeks ----------
    public function weekMeta(int $y, int $w): array
    {
        $meta = [];
        foreach (self::WEEK_FIELDS as [$k, , $kind]) {
            $meta[$k] = $kind === 'int' ? null : '';
        }
        $meta = array_merge($meta, $this->weeks[self::key($y, $w)] ?? []);
        // The dates follow the epi week (Monday to Sunday; bulletin on the Thursday after), whatever was typed or uploaded.
        [$meta['start'], $meta['end']] = self::weekDates($y, $w);
        $meta['published'] = (new DateTimeImmutable($meta['end']))->modify('+4 days')->format('Y-m-d');

        return $meta;
    }

    /** Save one week from the entry page: its details and the full count grid (blank counts are 0). */
    public function putWeek($year, $week, $meta, $counts): array
    {
        [$y, $w] = self::checkWeek($year, $week);
        $meta = self::cleanMeta(is_array($meta) ? $meta : []);
        self::checkReports($meta);
        $names = $this->names();
        $grid = [];
        $errors = [];
        foreach (is_array($counts) ? $counts : [] as $code => $vals) {
            $code = (string) $code;
            if (! isset($names[$code])) {
                throw new InvalidArgumentException("Unknown district code $code.");
            }
            $row = [];
            foreach (self::heads() as $c => $h) {
                try {
                    $row[$c] = self::toInt(is_array($vals) ? ($vals[$c] ?? null) : null, "{$names[$code]}, $h") ?? 0;
                } catch (InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $grid[$code] = $row;
        }
        if ($errors) {
            throw new InvalidArgumentException(implode('; ', array_slice($errors, 0, 5)));
        }
        $k = self::key($y, $w);
        $this->weeks[$k] = array_merge($this->weeks[$k] ?? [], $meta);
        $this->counts[$k] = array_replace($this->counts[$k] ?? [], $grid);
        $this->save();

        return [$y, $w];
    }

    /** Remove one week (its details and every district's counts). The previous file is kept as .bak.xlsx. */
    public function deleteWeek($year, $week): array
    {
        [$y, $w] = self::checkWeek($year, $week);
        $k = self::key($y, $w);
        if (! isset($this->weeks[$k]) && ! isset($this->counts[$k])) {
            throw new InvalidArgumentException(sprintf('Week %03d of %d is not on file.', $w, $y));
        }
        unset($this->weeks[$k], $this->counts[$k]);
        $this->save();

        return [$y, $w];
    }

    public function setSettings(array $raw): void
    {
        $region = self::text($raw['region'] ?? null);
        $code = strtoupper(self::text($raw['code'] ?? null));
        if ($region === '') {
            throw new InvalidArgumentException('Enter the region name.');
        }
        $districts = [];
        $seen = [];
        foreach ((array) ($raw['districts'] ?? []) as $line) {
            [$c, $name] = [strtoupper(self::text($line[0] ?? null)), self::text($line[1] ?? null)];
            if ($c === '' || $name === '') {
                continue;
            }
            if (isset($seen[$c])) {
                throw new InvalidArgumentException("District code $c is used twice.");
            }
            $seen[$c] = true;
            $districts[] = [$c, $name];
        }
        if (! $districts) {
            throw new InvalidArgumentException('List at least one district.');
        }
        $inUse = [];
        foreach ($this->counts as $grid) {
            foreach ($grid as $c => $_) {
                if (! isset($seen[$c])) {
                    $inUse[(string) $c] = true;
                }
            }
        }
        $inUse = array_keys($inUse);
        sort($inUse);
        if ($inUse && $this->region !== '' && self::norm($region) !== self::norm($this->region)) {
            throw new InvalidArgumentException("The weeks on file are for {$this->region}, so the region can't be changed to $region. "
                .'Keep a separate copy of '.basename($this->path).' for each region.');
        }
        if ($inUse) {
            $one = count($inUse) === 1;
            throw new InvalidArgumentException(implode(', ', $inUse).' already '.($one ? 'has' : 'have').' weekly data, so '
                .($one ? 'it' : 'they')." can't be removed. Rename instead.");
        }
        [$this->region, $this->code, $this->districts] = [$region, $code, $districts];
        $this->board = [];
        foreach ((array) ($raw['board'] ?? []) as $b) {
            if (self::text($b[0] ?? null) !== '') {
                $this->board[] = [self::text($b[0]), self::text($b[1] ?? null)];
            }
        }
        $this->actions = array_values(array_filter(array_map([self::class, 'text'], (array) ($raw['actions'] ?? [])), fn ($a) => $a !== ''));
        $this->save();
    }

    // ---------- import ----------
    /** Compare an upload with the store. Nothing is written; applyImport($plan) does that. */
    public function planImport(array $forms, ?array $table): array
    {
        $problems = [];
        $notes = [];
        $incoming = [];
        $before = array_column($this->districts, 0);
        foreach ($forms as $f) {
            array_push($problems, ...$f['problems']);
            if (! $f['year'] || ! $f['week']) {
                $problems[] = "Sheet “{$f['sheet']}”: the week or year could not be read from the form.";
                continue;
            }
            try {
                [$y, $w] = self::checkWeek($f['year'], $f['week']);
            } catch (InvalidArgumentException $e) {
                $problems[] = "Sheet “{$f['sheet']}”: ".$e->getMessage();
                continue;
            }
            if ($f['region'] !== '' && $this->region === '') {
                $this->region = $f['region'];
                $this->code = $f['code'] ?: $this->code;
                $notes[] = "Region set to {$this->region}".($this->code ? " ({$this->code})" : '').' from the form.';
            } elseif ($f['region'] !== '' && self::norm($f['region']) !== self::norm($this->region)) {
                $problems[] = "Sheet “{$f['sheet']}” is a {$f['region']} form, but this store is for {$this->region}. Check it is the right file.";
            }
            $k = self::key($y, $w);
            $incoming[$k] ??= ['meta' => [], 'counts' => []];
            $incoming[$k]['meta'] = array_merge($incoming[$k]['meta'], array_filter($f['meta'], [self::class, 'filled']));
            foreach ($f['rows'] as [$label, $vals]) {
                $code = $this->districtCode($label, true);
                $incoming[$k]['counts'][$code] = array_replace($incoming[$k]['counts'][$code] ?? [], $vals);
            }
        }
        if ($table) {
            $this->planTable($table, $incoming, $problems, $notes);
        }

        return $this->planIncoming($incoming, $problems, $notes, $before);
    }

    /** Compare ["y-w" => {meta, counts}] with the store, as a plan for applyImport. */
    public function planIncoming(array $incoming, array $problems = [], array $notes = [], ?array $before = null): array
    {
        $before ??= array_column($this->districts, 0);
        $names = $this->names();
        $heads = self::heads();
        $keys = array_keys($incoming);
        usort($keys, fn ($a, $b) => self::yw($a) <=> self::yw($b));
        $weeks = [];
        $cells = 0;
        foreach ($keys as $k) {
            [$y, $w] = self::yw($k);
            $inc = $incoming[$k];
            $old = $this->counts[$k] ?? [];
            $changes = [];
            $cases = 0;
            foreach ($inc['counts'] as $code => $vals) {
                foreach ($heads as $c => $h) {
                    $v = $vals[$c] ?? null;
                    $was = $old[$code][$c] ?? null;
                    if ($v !== null && $v !== $was) {
                        $changes[] = ['district' => $names[$code] ?? (string) $code, 'field' => $h, 'old' => $was, 'new' => $v];
                    }
                    if (str_ends_with($c, '.CS')) {
                        $cases += $v ?? 0;
                    }
                }
            }
            $cur = $this->weeks[$k] ?? [];
            $meta = [];
            foreach ($inc['meta'] as $f => $v) {
                if (self::filled($v) && $v !== ($cur[$f] ?? null)) {
                    $meta[] = ['field' => self::weekField($f)[1], 'old' => $cur[$f] ?? null, 'new' => $v];
                }
            }
            $cells += count($changes);
            $weeks[] = ['year' => $y, 'week' => $w, 'new' => ! isset($this->weeks[$k]), 'districts' => count($inc['counts']),
                'cases' => $cases, 'changeCount' => count($changes), 'changes' => array_slice($changes, 0, 300), 'meta' => $meta];
        }
        $added = array_values(array_filter($this->districts, fn ($d) => ! in_array($d[0], $before, true)));

        return ['weeks' => $weeks, 'newDistricts' => $added, 'problems' => $problems, 'notes' => $notes,
            'summary' => ['weeks' => count($weeks), 'newWeeks' => count(array_filter($weeks, fn ($w) => $w['new'])), 'cells' => $cells,
                'meta' => array_sum(array_map(fn ($w) => count($w['meta']), $weeks))],
            '_incoming' => $incoming];
    }

    private function planTable(array $rows, array &$incoming, array &$problems, array &$notes): void
    {
        $hdr = null;
        foreach (array_slice($rows, 0, 10) as $i => $r) {
            $h = array_map([self::class, 'norm'], $r);
            if (in_array('year', $h, true) && in_array('week', $h, true) && array_filter($h, fn ($x) => str_contains($x, 'district'))) {
                $hdr = $i;
                break;
            }
        }
        if ($hdr === null) {
            $problems[] = 'No IDSR weekly form and no header row with Year, Week and District columns was found.';

            return;
        }
        $head = array_map([self::class, 'norm'], $rows[$hdr]);
        $col = function (...$names) use ($head) {
            foreach ($names as $n) {
                foreach ($head as $i => $h) {
                    if ($h === $n) {
                        return $i;
                    }
                }
            }

            return null;
        };
        [$iy, $iw] = [$col('year'), $col('week')];
        $idist = $col('districtcode', 'code', 'district', 'districtname', 'nameofdistrict', 'mdistrict', 'md');
        $heads = self::heads();
        $alias = [];
        foreach (self::diseases() as $d) {
            foreach ($d['measures'] as $m) {
                foreach ([$d['short'], $d['name'], $d['key']] as $a) {
                    foreach ([$m, self::MEASURES[$m]] as $mm) {
                        $alias[self::norm("$a $mm")] ??= "{$d['key']}.$m";
                    }
                }
            }
        }
        $metaAlias = [];
        foreach (self::WEEK_FIELDS as [$k, $h]) {
            if (! in_array($k, ['start', 'end'], true)) {
                $metaAlias[self::norm($h)] = $k;
            }
        }
        $long = [$col('disease'), $col('measure'), $col('value')];
        $isLong = ! in_array(null, $long, true);
        $dis = [];
        $meas = [];
        if ($isLong) {
            foreach (self::diseases() as $d) {
                foreach ([$d['short'], $d['name'], $d['key']] as $a) {
                    $dis[self::norm($a)] = $d;
                }
            }
            foreach (self::MEASURES as $m => $label) {
                $meas[self::norm($m)] = $m;
                $meas[self::norm($label)] = $m;
            }
        }
        $mapped = [];
        $metas = [];
        foreach ($head as $i => $h) {
            if (isset($alias[$h])) {
                $mapped[$i] = $alias[$h];
            } elseif (isset($metaAlias[$h])) {
                $metas[$i] = $metaAlias[$h];
            }
        }
        $ignored = [];
        foreach ($head as $i => $h) {
            if ($h !== '' && ! isset($mapped[$i]) && ! isset($metas[$i]) && ! in_array($i, [$iy, $iw, $idist, ...$long], true)
                && ! in_array($h, ['district', 'districtname'], true)) {
                $ignored[] = self::text($rows[$hdr][$i]);
            }
        }
        if ($ignored) {
            $notes[] = 'Columns not used: '.implode(', ', array_slice($ignored, 0, 12)).(count($ignored) > 12 ? ' …' : '');
        }
        if (! $isLong && ! $mapped) {
            $problems[] = 'The table has no disease columns such as “AFP CS” or “Measles D”.';

            return;
        }
        foreach (array_slice($rows, $hdr + 1) as $off => $r) {
            $n = $hdr + 2 + $off;
            $cell = fn ($i) => $i !== null ? ($r[$i] ?? null) : null;
            if (! array_filter($r, [self::class, 'filled'])) {
                continue;
            }
            try {
                [$y, $w] = self::checkWeek($cell($iy), $cell($iw));
            } catch (InvalidArgumentException $e) {
                $problems[] = "Row $n: ".$e->getMessage();
                continue;
            }
            if (self::norm($cell($idist)) === '') {
                $problems[] = "Row $n: no district.";
                continue;
            }
            try {
                if ($isLong) {
                    $d = $dis[self::norm($cell($long[0]))] ?? null;
                    $m = $meas[self::norm($cell($long[1]))] ?? null;
                    if (! $d || ! in_array($m, $d['measures'], true)) {
                        throw new InvalidArgumentException('unknown disease or measure “'.self::text($cell($long[0])).' '.self::text($cell($long[1])).'”');
                    }
                    $vals = ["{$d['key']}.$m" => self::toInt($cell($long[2]), 'Value')];
                    $meta = [];
                } else {
                    $vals = [];
                    foreach ($mapped as $i => $c) {
                        if (($v = self::toInt($cell($i), $heads[$c])) !== null) {
                            $vals[$c] = $v;
                        }
                    }
                    $raw = [];
                    foreach ($metas as $i => $k) {
                        $raw[$k] = $cell($i);
                    }
                    $meta = array_filter(self::cleanMeta($raw), [self::class, 'filled']);
                }
            } catch (InvalidArgumentException $e) {
                $problems[] = "Row $n: ".$e->getMessage();
                continue;
            }
            $k = self::key($y, $w);
            $incoming[$k] ??= ['meta' => [], 'counts' => []];
            $code = $this->districtCode($cell($idist), true);
            $incoming[$k]['counts'][$code] = array_replace($incoming[$k]['counts'][$code] ?? [], $vals);
            $incoming[$k]['meta'] = array_merge($incoming[$k]['meta'], $meta);
        }
    }

    public function applyImport(array $plan): int
    {
        foreach ($plan['_incoming'] as $k => $inc) {
            $this->weeks[$k] = array_merge($this->weeks[$k] ?? [], $inc['meta']);
            foreach ($inc['counts'] as $code => $vals) {
                $this->counts[$k][$code] = array_replace($this->counts[$k][$code] ?? [], array_filter($vals, fn ($v) => $v !== null));
            }
        }
        $this->save();

        return count($plan['_incoming']);
    }

    // ---------- exports ----------
    public function weekCsv(int $y, int $w): string
    {
        $grid = $this->counts[self::key($y, $w)] ?? [];
        $cols = self::cols();
        $f = fopen('php://temp', 'r+');
        fputcsv($f, array_merge(['Year', 'Week', 'District code', 'District'], array_values(self::heads())), ',', '"', '');
        foreach ($this->districts as [$code, $name]) {
            fputcsv($f, array_merge([$y, $w, $code, $name], array_map(fn ($c) => $grid[$code][$c] ?? '', $cols)), ',', '"', '');
        }
        rewind($f);
        $body = stream_get_contents($f);
        fclose($f);

        return "\xEF\xBB\xBF".$body;
    }

    public static function formTemplate(): string
    {
        return resource_path('data/idsr-weekly-form-template.xlsx');
    }

    /** The week written into the official weekly reporting form. */
    public function formXlsx(int $y, int $w): string
    {
        if (! is_file(self::formTemplate())) {
            throw new InvalidArgumentException('The form template is missing: resources/data/'.basename(self::formTemplate()));
        }
        $wb = IOFactory::createReader('Xlsx')->load(self::formTemplate());
        $ws = $wb->getSheet(0);
        $meta = $this->weekMeta($y, $w);
        $grid = $this->counts[self::key($y, $w)] ?? [];
        $num = fn ($v) => $v === null ? '' : $v;
        $maxRow = $ws->getHighestRow();
        $maxCol = Coordinate::columnIndexFromString($ws->getHighestColumn());

        $put = function (string $pattern, string $value) use ($ws, $maxRow, $maxCol) {
            for ($r = 1; $r <= $maxRow; $r++) {
                for ($c = 1; $c <= $maxCol; $c++) {
                    $v = $ws->cellExists([$c, $r]) ? $ws->getCell([$c, $r])->getValue() : null;
                    if (is_string($v) && preg_match($pattern.'i', $v)) {
                        $ws->getCell([$c, $r])->setValueExplicit($value, DataType::TYPE_STRING);

                        return;
                    }
                }
            }
        };

        $put('/^\s*IDSR Summary/', sprintf('IDSR Summary Regional Weekly _%03d_ %d  Reporting Form_%s', $w, $y, $this->code));
        $put('/Reporting Region/', 'Reporting Region:   '.strtoupper($this->region));
        $put('/Region Code/', "Region Code: {$this->code}");
        $put('/^\s*Week\s*:/', sprintf('Week: %03d', $w));
        $put('/^\s*Year\s*:/', "Year: $y");
        $put('/Week Beginning/', 'Week Beginning Monday: '.self::dmy($meta['start']));
        $put('/Week Ending/', 'Week Ending Sunday: '.self::dmy($meta['end']));
        $put('/Expected Number of Reports/', 'Officially Expected Number of Reports:  '.$num($meta['expected']));
        $put('/Reports received\s*:/', 'Number of Reports received:  '.$num($meta['received']));
        $put('/received on time/', 'Number of Reports received on time: '.$num($meta['ontime']));
        $put('/Name of Person reporting/', "Name of Person reporting:     {$meta['reporter']}");
        $put('/^\s*Telephone/', "Telephone No.:      {$meta['phone']}");
        $put('/Date Reporting Form Filled/', 'Date Reporting Form Filled: '.self::dmy($meta['filled']).'   Date Reporting Form Submitted '
            .'to next level: '.self::dmy($meta['submitted']).'   Date Received at next level:      /     /   /');
        $epi = $meta['epi'].($meta['comments'] !== '' ? "\n\nComments: ".$meta['comments'] : '');
        foreach (['/^\s*Epidemiological/i' => $epi, '/^\s*Decisions/i' => $meta['decisions'], '/^\s*Recommendations/i' => $meta['recommendations']] as $pattern => $value) {
            for ($r = 1; $r <= $maxRow; $r++) {
                $v = Xl::at($ws, $r, 2);
                if (is_string($v) && preg_match($pattern, $v)) {
                    self::put($ws, 3, $r, $value);
                    break;
                }
            }
        }
        foreach (self::growBlocks($ws, count($this->districts)) as $i => [, $rows]) {
            foreach ($rows as $k => $r) {
                [$code, $name] = $this->districts[$k] ?? [null, null];
                self::put($ws, 2, $r, $name !== null ? strtoupper($name) : null);
                $vals = $code !== null ? ($grid[$code] ?? null) : null;
                $c = 3;
                foreach (self::BLOCKS[$i] as $d) {
                    foreach (explode(' ', $d[3]) as $m) {
                        $ws->getCell([$c, $r])->setValue($vals === null ? null : ($vals["{$d[0]}.$m"] ?? 0));
                        $c++;
                    }
                }
            }
        }

        return Register::bytes($wb);
    }

    public function snapshot(): array
    {
        $weeks = [];
        foreach ($this->weekKeys() as $k) {
            [$y, $w] = self::yw($k);
            $weeks[] = ['year' => $y, 'week' => $w] + $this->weekMeta($y, $w);
        }
        $counts = [];
        foreach ($this->counts as $k => $grid) {
            $counts[$k] = (object) array_map(fn ($v) => (object) $v, $grid);
        }

        return ['region' => $this->region, 'code' => $this->code, 'districts' => $this->districts, 'board' => $this->board,
            'actions' => $this->actions, 'orgUnits' => OrgUnits::all(), 'diseases' => self::diseases(), 'measures' => self::MEASURES,
            'heads' => self::heads(), 'weeks' => $weeks, 'counts' => (object) $counts];
    }

    // ---------- one store per region ----------
    public static function mainPath(): string
    {
        return rtrim(config('surveillance.data_dir'), '/\\').'/IDSR weekly data.xlsx';
    }

    public static function regionDir(): string
    {
        return rtrim(config('surveillance.data_dir'), '/\\').'/IDSR regions';
    }

    /**
     * The store of $region (one of the org unit regions); without one, the main store.
     *
     * The main store, IDSR weekly data.xlsx, keeps the region it was first set up for. Other regions are kept in
     * IDSR regions/IDSR weekly data - <Region>.xlsx. A region with no file yet starts with its org unit districts and
     * the main store's standard actions, and is saved with its first week.
     */
    public static function for(?string $region = null): self
    {
        $main = new self(self::mainPath());
        if (self::text($region) === '') {
            return $main;
        }
        if (OrgUnits::bare($region) === OrgUnits::bare(OrgUnits::NATIONAL)) {
            // Ghana: its own file, whose rows are the regions, entered like a region's districts (form, upload or
            // DHIMS2 fetched by region). It is not added up from the regions' files.
            $store = new self(self::regionDir().'/IDSR weekly data - '.OrgUnits::NATIONAL.'.xlsx');
            $store->national = true;
            if ($store->region === '') {
                $unit = OrgUnits::nationalUnit();
                [$store->region, $store->code, $store->districts, $store->actions] = [$unit['name'], $unit['code'], $unit['districts'], $main->actions];
            }

            return $store;
        }
        $unit = OrgUnits::regionOf($region);
        if (! $unit) {
            throw new InvalidArgumentException("Unknown region $region.");
        }
        $home = OrgUnits::regionOf($main->region);
        $store = $main->region === '' || ($home && $home['name'] === $unit['name'])
            ? $main : new self(self::regionDir()."/IDSR weekly data - {$unit['name']}.xlsx");
        if ($store->region === '') {
            [$store->region, $store->code, $store->districts, $store->actions] = [$unit['name'], $unit['code'], $unit['districts'], $main->actions];
        }

        return $store;
    }

    /** Names of the regions that have a store, the main one first. */
    public static function regionsOnFile(): array
    {
        $main = new self(self::mainPath());
        $out = $main->region !== '' ? [$main->region] : [];
        $files = glob(self::regionDir().'/IDSR weekly data - *.xlsx') ?: [];
        sort($files);
        foreach ($files as $p) {
            $stem = pathinfo($p, PATHINFO_FILENAME);
            if (! str_ends_with($stem, '.bak')) {
                $out[] = explode(' - ', $stem, 2)[1];
            }
        }

        return $out;
    }

    // ---------- the official weekly form ----------
    /** [header row, district rows] for each disease table: the rows under 'Name of District' down to 'Total'. */
    public static function formBlocks(Worksheet $ws): array
    {
        $out = [];
        $max = $ws->getHighestRow();
        for ($r = 1; $r <= $max; $r++) {
            if (self::norm(Xl::at($ws, $r, 2)) === 'nameofdistrict') {
                $rows = [];
                $k = $r + 1;
                while ($k <= $max && ! in_array(self::norm(Xl::at($ws, $k, 2)), ['total', ''], true)) {
                    $rows[] = $k;
                    $k++;
                }
                $out[] = [$r, $rows];
            }
        }

        return $out;
    }

    /** The form's disease tables with at least $n district rows each; the Total rows add up the new rows too. */
    public static function growBlocks(Worksheet $ws, int $n): array
    {
        $out = [];
        $offset = 0;
        foreach (self::formBlocks($ws) as [$head, $rows]) {
            $head += $offset;
            $rows = array_map(fn ($r) => $r + $offset, $rows);
            if ($rows && $n > count($rows)) {
                $k = $n - count($rows);
                $like = end($rows);
                $height = $ws->getRowDimension($like)->getRowHeight();
                // PhpSpreadsheet moves merged cells, row heights and references down, and styles the new rows like
                // the row above them.
                $ws->insertNewRowBefore($like + 1, $k);
                for ($r = $like + 1; $r <= $like + $k; $r++) {
                    $ws->getRowDimension($r)->setRowHeight($height);
                }
                $rows = array_merge($rows, range($like + 1, $like + $k));
                $offset += $k;
            }
            $out[] = [$head, $rows];
        }
        $maxCol = Coordinate::columnIndexFromString($ws->getHighestColumn());
        foreach ($out as [, $rows]) {  // every table's totals are rewritten to cover the new rows
            if (! $rows) {
                continue;
            }
            for ($c = 3; $c <= $maxCol; $c++) {
                $cell = $ws->getCell([$c, end($rows) + 1]);
                $v = $cell->getValue();
                if (is_string($v) && str_starts_with(strtoupper($v), '=SUM(')) {
                    $l = Coordinate::stringFromColumnIndex($c);
                    $cell->setValue("=SUM($l{$rows[0]}:$l".end($rows).')');
                }
            }
        }

        return $out;
    }

    public static function isForm(Worksheet $ws): bool
    {
        return count(self::formBlocks($ws)) === count(self::BLOCKS);
    }

    /** One filled-in weekly form: week, region, details and counts by district name. */
    public static function parseForm(Worksheet $ws): array
    {
        $title = $ws->getTitle();
        $grid = Xl::rows($ws, null, true);
        $cells = [];
        foreach ($grid as $ri => $row) {
            foreach ($row as $ci => $v) {
                if (is_string($v) && trim($v) !== '') {
                    $cells[] = [$ri + 1, $ci + 1, self::text($v)];
                }
            }
        }
        $find = function (string $pattern, ?int $onlyRow = null) use ($cells) {
            foreach ($cells as [$r, , $t]) {
                if (($onlyRow === null || $onlyRow === $r) && preg_match($pattern.'i', $t, $m)) {
                    return trim($m[1]);
                }
            }

            return '';
        };
        $beside = function (string $pattern) use ($cells, $grid) {
            foreach ($cells as [$r, $col, $t]) {
                if ($col === 2 && preg_match($pattern.'i', $t)) {
                    $v = $grid[$r - 1][2] ?? null;

                    return $v !== null ? trim(self::s($v)) : '';
                }
            }

            return '';
        };
        $titleMatch = null;
        foreach ($cells as [, , $t]) {
            if (preg_match('/_(\d{1,3})_\s*(\d{4})/', $t, $m)) {
                $titleMatch = $m;
                break;
            }
        }
        $year = self::safeInt($find('/\byear\s*:\s*(\d{4})/')) ?: ($titleMatch ? self::safeInt($titleMatch[2]) : null);
        $week = self::safeInt($find('/^week\s*:\s*0*(\d{1,2})\b/')) ?: ($titleMatch ? self::safeInt($titleMatch[1]) : null);
        $reporterRow = null;
        foreach ($cells as [$r, , $t]) {
            if (preg_match('/name of person reporting/i', $t)) {
                $reporterRow = $r;
                break;
            }
        }
        $problems = [];
        $raw = [
            'start' => $find('/week beginning[^:]*:\s*([\d\/.\-]+)/'), 'end' => $find('/week ending[^:]*:\s*([\d\/.\-]+)/'),
            'expected' => $find('/expected number of reports\s*:\s*(\d+)/'), 'received' => $find('/reports received\s*:\s*(\d+)/'),
            'ontime' => $find('/received on time\s*:\s*(\d+)/'),
            'epi' => $beside('/^epidemiological/'), 'decisions' => $beside('/^decisions/'), 'recommendations' => $beside('/^recommendations/'),
            'reporter' => $find('/name of person reporting\s*:\s*(.*)$/'),
            'phone' => $reporterRow ? $find('/telephone no\.?\s*:\s*([\d +]*)/', $reporterRow) : '',
            'filled' => $find('/form filled\s*:\s*([\d\/.\-]+)/'), 'submitted' => $find('/form submitted[^:]*:\s*([\d\/.\-]+)/'),
        ];
        try {
            $meta = self::cleanMeta($raw);
        } catch (InvalidArgumentException $e) {
            $problems[] = "Sheet “{$title}”: ".$e->getMessage();
            $meta = array_filter($raw, fn ($k) => self::weekField($k)[2] === 'text', ARRAY_FILTER_USE_KEY);
        }
        $rows = [];
        foreach (self::formBlocks($ws) as $i => [$headRow, $drows]) {
            $heading = implode(' ', array_map(fn ($c) => strtolower($c[2]),
                array_filter($cells, fn ($c) => $c[0] >= $headRow - 2 && $c[0] < $headRow && $c[1] >= 3)));
            $missing = array_map(fn ($d) => $d[1], array_filter(self::BLOCKS[$i], fn ($d) => ! preg_match($d[4], $heading)));
            if ($missing) {
                throw new InvalidArgumentException("Sheet “{$title}” does not match the standard IDSR weekly form: table ".($i + 1).' has no '
                    .implode(', ', $missing).' column. Upload it as a table with Year, Week and District columns instead.');
            }
            foreach ($drows as $r) {
                $name = self::text($grid[$r - 1][1] ?? null);
                $vals = [];
                $c = 3;
                foreach (self::BLOCKS[$i] as $d) {
                    foreach (explode(' ', $d[3]) as $m) {
                        try {
                            $vals["{$d[0]}.$m"] = self::toInt($grid[$r - 1][$c - 1] ?? null, "$name, {$d[2]} $m");
                        } catch (InvalidArgumentException $e) {
                            $problems[] = "Sheet “{$title}”, ".Coordinate::stringFromColumnIndex($c)."$r: ".$e->getMessage();
                        }
                        $c++;
                    }
                }
                $rows[] = [$name, $vals];
            }
        }
        $region = self::text($find('/reporting region\s*:\s*(.*)$/'));

        return ['sheet' => $title, 'year' => $year, 'week' => $week, 'meta' => $meta, 'rows' => $rows, 'problems' => $problems,
            'region' => ucwords(strtolower($region)), 'code' => strtoupper($find('/region code\s*:\s*(\w+)/'))];
    }

    /** [forms, table] from an uploaded file: every sheet that is an official weekly form, otherwise the rows of a table. */
    public static function readUpload(string $name, string $data): array
    {
        $suffix = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($suffix, ['xlsx', 'xlsm'], true)) {
            $tmp = tempnam(sys_get_temp_dir(), 'idsr');
            file_put_contents($tmp, $data);
            try {
                $wb = IOFactory::createReader('Xlsx')->load($tmp);
            } catch (\Throwable) {
                throw new InvalidArgumentException('That file could not be opened as an Excel workbook.');
            } finally {
                @unlink($tmp);
            }
            try {
                $forms = [];
                foreach ($wb->getWorksheetIterator() as $ws) {
                    if (self::isForm($ws)) {
                        $forms[] = self::parseForm($ws);
                    }
                }

                return $forms ? [$forms, null] : [[], Xl::rows($wb->getSheet(0), null, true)];
            } finally {
                $wb->disconnectWorksheets();
            }
        }
        if ($suffix === 'csv') {
            return [[], self::readCsv($data)];
        }
        if ($suffix === 'xls') {
            throw new InvalidArgumentException('Old .xls files are not supported. Save it as .xlsx or .csv in Excel first.');
        }
        throw new InvalidArgumentException('Upload an .xlsx or .csv file.');
    }

    private static function readCsv(string $data): array
    {
        if (str_starts_with($data, "\xEF\xBB\xBF")) {
            $data = substr($data, 3);
        }
        if (! mb_check_encoding($data, 'UTF-8')) {
            $data = mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
        }
        $first = strtok(substr($data, 0, 4096), "\n") ?: '';
        $delim = ',';
        $best = -1;
        foreach ([',', ';', "\t"] as $d) {
            if (substr_count($first, $d) > $best) {
                $best = substr_count($first, $d);
                $delim = $d;
            }
        }
        $f = fopen('php://temp', 'r+');
        fwrite($f, $data);
        rewind($f);
        $rows = [];
        while (($r = fgetcsv($f, null, $delim, '"', '')) !== false) {
            $rows[] = $r === [null] ? [] : $r;
        }
        fclose($f);

        return $rows;
    }
}
