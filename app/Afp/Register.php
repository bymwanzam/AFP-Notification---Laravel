<?php

namespace App\Afp;

use App\Support\OrgUnits;
use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The AFP notification register (an Excel workbook): its columns and checks, new cases, edits, imports and
 * exports. Problems with what was sent are thrown as \InvalidArgumentException with the message to show.
 */
class Register
{
    public const FUP_DAYS = 60;

    public string $path;
    public int $headerRow;
    public int $lastRow;
    /** @var Field[] */
    public array $fields = [];
    public array $existing = [];
    public array $codeMap = [];
    public ?array $home = null;
    public array $regions = [];

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->reload();
    }

    public static function load(string $path): Spreadsheet
    {
        if (! is_file($path)) {
            throw new \RuntimeException(basename($path).' was not found.');
        }

        return IOFactory::createReader('Xlsx')->load($path);
    }

    public static function findHeaderRow(Worksheet $ws): int
    {
        for ($r = 1; $r <= 10; $r++) {
            $texts = [];
            $max = Coordinate::columnIndexFromString($ws->getHighestColumn());
            for ($c = 1; $c <= $max; $c++) {
                $v = Xl::at($ws, $r, $c);
                if ($v !== null && $v !== '') {
                    $texts[] = strtolower(Text::clean($v));
                }
            }
            if (count($texts) > 5 && array_filter($texts, fn ($t) => str_contains($t, 'epid'))) {
                return $r;
            }
        }

        return 1;
    }

    public static function lastDataRow(Worksheet $ws, int $headerRow, int $ncols): int
    {
        $last = $headerRow;
        $max = $ws->getHighestRow();
        for ($r = $headerRow + 1; $r <= $max; $r++) {
            for ($c = 1; $c <= $ncols; $c++) {
                $v = Xl::at($ws, $r, $c);
                if ($v !== null && $v !== '') {
                    $last = $r;
                    break;
                }
            }
        }

        return $last;
    }

    /** The column's in-sheet dropdown list, preferring the one covering $row, else any below the header. */
    public static function dvListFor(Worksheet $ws, string $letter, int $row): ?array
    {
        $col = Coordinate::columnIndexFromString($letter);
        $lists = [];
        foreach ($ws->getDataValidationCollection() as $sqref => $dv) {
            $f = (string) $dv->getFormula1();
            if ($dv->getType() === 'list' && $f !== '' && str_contains($f, '"')) {
                $lists[] = [$dv->getSqref() ?: $sqref, $f];
            }
        }
        $pick = null;
        foreach ($lists as [$sqref, $f]) {
            if (Xl::covers($sqref, $col, $row)) {
                $pick = $f;
                break;
            }
        }
        if ($pick === null) {
            foreach ($lists as [$sqref, $f]) {
                foreach (Xl::ranges($sqref) as [$c1, , $c2, $r2]) {
                    if ($c1 <= $col && $col <= $c2 && $r2 > 2) {
                        $pick = $f;
                        break 2;
                    }
                }
            }
        }
        if ($pick === null) {
            return null;
        }
        $items = array_map(fn ($i) => rtrim(trim($i), '.'), explode(',', trim($pick, '"')));

        return array_values(array_filter($items, fn ($i) => $i !== ''));
    }

    public function reload(): void
    {
        $wb = self::load($this->path);
        $ws = $wb->getActiveSheet();
        $this->headerRow = self::findHeaderRow($ws);
        $ncols = 0;
        $max = Coordinate::columnIndexFromString($ws->getHighestColumn());
        for ($c = 1; $c <= $max; $c++) {
            if (! Text::blank(Xl::at($ws, $this->headerRow, $c))) {
                $ncols = $c;
            }
        }
        $this->lastRow = self::lastDataRow($ws, $this->headerRow, $ncols);
        $this->fields = [];
        for ($c = 1; $c <= $ncols; $c++) {
            $this->fields[] = new Field($ws, $c, $this->headerRow, $this->lastRow);
        }
        $this->existing = [];
        for ($r = $this->headerRow + 1; $r <= $this->lastRow; $r++) {
            $row = [];
            for ($c = 1; $c <= $ncols; $c++) {
                $row[] = Xl::at($ws, $r, $c);
            }
            $this->existing[] = $row;
        }
        $wb->disconnectWorksheets();
        $this->codeMap = [];
        $district = $this->fieldLike('district or');
        $code = $this->fieldLike('epid. code');
        if ($district && $code && $district->kind === 'dropdown' && $code->kind === 'dropdown'
            && count($district->options) === count($code->options)) {
            $this->codeMap = array_combine($district->options, $code->options);
        }
        $this->home = null;
        $this->regions = [];
        if ($this->codeMap) {
            $this->addOrgUnits($district, $code);
        }
    }

    /**
     * Let the district column take a district from any region, picked region first. The register's own region keeps
     * the sheet's spellings; its in-sheet dropdown still lists only those.
     */
    private function addOrgUnits(Field $district, Field $code): void
    {
        $own = array_flip($this->codeMap);  // code => name
        foreach (array_keys($this->codeMap) as $n) {
            if ($r = OrgUnits::districtRegion($n)[0]) {
                $this->home = $r;
                break;
            }
        }
        $ef = $this->fieldLike('epid #');
        $ids = $ef ? array_map(fn ($r) => Text::str($r[$ef->col - 1]), $this->existing) : [];
        // The register's own EPID region code, from its latest case of one of its own districts.
        $homeEpid = null;
        foreach (array_reverse($ids) as $i) {
            if (preg_match('/^GHA-([A-Z]+)-([A-Z]+)-/', $i, $m) && isset($own[$m[2]])) {
                $homeEpid = $m[1];
                break;
            }
        }
        foreach (OrgUnits::all() as $r) {
            $mine = $this->home && $r['name'] === $this->home['name'];
            $districts = array_map(fn ($d) => [$d[0], $mine ? ($own[$d[0]] ?? $d[1]) : $d[1]], $r['districts']);
            if ($mine) {
                $have = array_column($districts, 0);
                foreach ($own as $c => $n) {
                    if (! in_array($c, $have, true)) {
                        $districts[] = [$c, $n];
                    }
                }
            }
            $this->regions[] = ['name' => $r['name'], 'epid' => $mine && $homeEpid ? $homeEpid : $r['epid'], 'districts' => $districts];
            foreach ($districts as [$c, $n]) {
                if (! array_key_exists($n, $this->codeMap)) {
                    $this->codeMap[$n] = $c;
                    $district->options[] = $n;
                }
                if (! in_array($c, $code->options, true)) {
                    $code->options[] = $c;
                }
            }
        }
    }

    /** Whether a reporting district is in $region; a district the register can't place counts as its own region's. */
    public function inRegion($district, ?string $region): bool
    {
        if (! $region) {
            return true;
        }
        $r = OrgUnits::districtRegion($district)[0];

        return ($r['name'] ?? $this->home['name'] ?? null) === $region;
    }

    /** [col, message] when a new case's reporting district is outside $region, else null. */
    public function districtError(array $values, ?string $region): ?array
    {
        $f = $this->fieldLike('district or');
        if ($f && ! empty($values[$f->col]) && ! $this->inRegion($values[$f->col], $region)) {
            return [$f->col, "{$values[$f->col]} is not in $region Region. Choose one of its districts."];
        }

        return null;
    }

    public function fieldLike(string $text): ?Field
    {
        foreach ($this->fields as $f) {
            if (str_contains(strtolower($f->label), $text)) {
                return $f;
            }
        }

        return null;
    }

    public function snField(): ?Field
    {
        return $this->fields && $this->fields[0]->kind === 'number' ? $this->fields[0] : null;
    }

    public function nextSn(): int
    {
        $nums = array_filter(array_column($this->existing, 0), fn ($v) => is_int($v) || is_float($v));

        return (int) ($nums ? max($nums) : 0) + 1;
    }

    /** EPID # (upper case) => row values. */
    public function epids(): array
    {
        $f = $this->fieldLike('epid #');
        $out = [];
        foreach ($this->existing as $r) {
            $v = $r[$f->col - 1];
            if ($v !== null && $v !== '' && $v !== 0) {
                $out[strtoupper(trim(Text::str($v)))] = $r;
            }
        }

        return $out;
    }

    /** Next EPID # for a district code: GHA-<region>-<code>-<yy>-<nnn>. Without a region, the register's own. */
    public function nextEpid(string $code, ?string $region = null): string
    {
        $code = trim($code);
        $f = $this->fieldLike('epid #');
        $ids = [];
        foreach ($this->existing as $r) {
            if ($r[$f->col - 1]) {
                $ids[] = Text::str($r[$f->col - 1]);
            }
        }
        if ($ids) {
            $parts = explode('-', end($ids));
            $prefix = implode('-', array_slice($parts, 0, max(count($parts) - 3, 1)));
        } else {
            $prefix = 'GHA';
        }
        foreach ($this->regions as $u) {
            if (strtolower($u['name']) === strtolower(Text::clean($region ?? ''))) {
                $prefix = 'GHA-'.$u['epid'];
                break;
            }
        }
        $yy = date('y');
        $max = 0;
        foreach ($ids as $i) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-'.preg_quote($code, '/').'-'.$yy.'-(\d+)$/', $i, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('%s-%s-%s-%03d', $prefix, $code, $yy, $max + 1);
    }

    public function schema(): array
    {
        $col = fn (?Field $f) => $f?->col;

        return [
            'fields' => array_map(fn (Field $f) => ['col' => $f->col, 'label' => $f->label, 'kind' => $f->kind,
                'options' => $f->options, 'strict' => $f->strict, 'required' => $f->required, 'allowAge' => $f->allowAge], $this->fields),
            'nextSn' => $this->nextSn(), 'nextRow' => $this->lastRow + 1, 'codeMap' => (object) $this->codeMap,
            'snCol' => $col($this->snField()), 'epidCol' => $col($this->fieldLike('epid #')),
            'nameCol' => $col($this->fieldLike('patient name')), 'districtCol' => $col($this->fieldLike('district or')),
            'codeCol' => $col($this->fieldLike('epid. code')), 'onsetCol' => $col($this->fieldLike('onset')),
            'outcomeCol' => $col($this->fieldLike('outcome')), 'reportCol' => $col($this->fieldLike('report availability')),
            'fupDays' => self::FUP_DAYS, 'regions' => $this->regions, 'homeRegion' => $this->home['name'] ?? null,
        ];
    }

    /** One typed or uploaded value as what the cell should hold: [value, error]. */
    public function convert(Field $f, $raw): array
    {
        if (Text::blank($raw)) {
            return [null, null];
        }
        if (is_float($raw) && floor($raw) == $raw) {
            $raw = (int) $raw;
        }
        if ($f->kind === 'date') {
            if ($raw instanceof DateTimeInterface) {
                return [DateTimeImmutable::createFromInterface($raw), null];
            }
            if ($f->allowAge && (is_int($raw) || is_float($raw))) {
                return [$raw, null];
            }
            $text = Text::clean($raw);
            if ($d = Text::parseDate($text)) {
                return [$d, null];
            }
            if ($f->allowAge && preg_match('/^\d+(\.\d+)?$/', $text)) {
                return [str_contains($text, '.') ? (float) $text : (int) $text, null];
            }

            return [null, 'enter a date as dd/mm/yyyy.'];
        }
        $digits = $f->options && ! array_filter($f->options, fn ($o) => ! ctype_digit((string) $o));
        if ($f->kind === 'number' || ($f->kind === 'dropdown' && $digits)) {
            if (is_int($raw) || is_float($raw)) {
                return [$raw, null];
            }
            $text = Text::clean($raw);
            if (preg_match('/^-?\d+$/', $text)) {
                return [(int) $text, null];
            }
            if (is_numeric($text)) {
                return [(float) $text, null];
            }

            return [null, 'must be a number.'];
        }
        $text = Text::clean($raw);
        if ($f->kind === 'dropdown' && $f->strict) {
            foreach ($f->options as $o) {
                if (strtolower($o) === strtolower($text)) {
                    return [$o, null];
                }
            }

            return [null, 'choose from the list.'];
        }

        return [$text, null];
    }

    /** $raw: [col => value]. Returns [[col => value], [[col, message]]]. $ownEpid: the case being edited. */
    public function validate(array $raw, ?string $ownEpid = null): array
    {
        $values = [];
        $errors = [];
        foreach ($this->fields as $f) {
            if ($f->kind === 'formula') {
                continue;
            }
            [$v, $err] = $this->convert($f, $raw[$f->col] ?? null);
            if ($err) {
                $errors[] = [$f->col, "{$f->label}: $err"];

                continue;
            }
            if ($v === null && $f->required) {
                $errors[] = [$f->col, "{$f->label} is required."];
            }
            $values[$f->col] = $v;
        }
        $ef = $this->fieldLike('epid #');
        $given = $ef ? ($values[$ef->col] ?? null) : null;
        $newId = $given ? strtoupper(trim(Text::str($given))) : '';
        if ($newId !== '' && isset($this->epids()[$newId]) && $newId !== $ownEpid) {
            $errors[] = [$ef->col, "{$ef->label} {$values[$ef->col]} already exists."];
        }
        $onset = $this->fieldLike('onset');
        if ($onset && ($values[$onset->col] ?? null) instanceof DateTimeInterface
            && $values[$onset->col]->format('Y-m-d') > date('Y-m-d')) {
            $errors[] = [$onset->col, 'Date of onset cannot be in the future.'];
        }

        return [$values, $errors];
    }

    private function fillCode(array &$raw): void
    {
        $district = $this->fieldLike('district or');
        $code = $this->fieldLike('epid. code');
        if (! $this->codeMap || ! $district || ! $code) {
            return;
        }
        $want = strtolower(Text::clean($raw[$district->col] ?? ''));
        foreach ($this->codeMap as $d => $c) {
            if (strtolower((string) $d) === $want) {
                $raw[$code->col] = $c;

                return;
            }
        }
    }

    /** Fill the SN and district code when left blank, then validate a new case. */
    public function prepareNew(array $raw): array
    {
        $sn = $this->snField();
        if ($sn && Text::blank($raw[$sn->col] ?? null)) {
            $raw[$sn->col] = $this->nextSn();
        }
        $code = $this->fieldLike('epid. code');
        if ($code && Text::blank($raw[$code->col] ?? null)) {
            $this->fillCode($raw);
        }

        return $this->validate($raw);
    }

    /** The entry columns of one case, as the form shows them (dates as yyyy-mm-dd). */
    public function caseValues(string $epid): array
    {
        $row = $this->epids()[$epid] ?? throw new \InvalidArgumentException("There is no case $epid.");
        $out = [];
        foreach ($this->fields as $f) {
            $v = $row[$f->col - 1];
            if ($f->kind === 'formula' || $v === null) {
                continue;
            }
            $out[$f->col] = $v instanceof DateTimeInterface ? $v->format('Y-m-d')
                : (is_float($v) && floor($v) == $v ? (string) (int) $v : (string) $v);
        }

        return $out;
    }

    /** Validate the edited values of a case. Its SN stays; its EPID # can be corrected, not blanked or duplicated. */
    public function prepareEdit(string $epid, array $raw): array
    {
        $old = $this->epids()[$epid] ?? throw new \InvalidArgumentException("There is no case $epid.");
        $ef = $this->fieldLike('epid #');
        if (Text::blank($raw[$ef->col] ?? null)) {
            return [[], [[$ef->col, "{$ef->label} is required."]]];
        }
        if ($sn = $this->snField()) {
            $raw[$sn->col] = $old[$sn->col - 1];
        }
        $this->fillCode($raw);

        return $this->validate($raw, $epid);
    }

    public function updateCase(string $epid, array $values)
    {
        return $this->write(fn (Worksheet $ws) => $this->setCells($ws, $epid, $values));
    }

    private function formulaFor(string $template, int $from, int $to): string
    {
        return preg_replace('/(?<=[A-Z])'.$from.'(?!\d)/', (string) $to, $template);
    }

    /** Remove a case's row. Rows below move up, and their formulas are written again for their new rows. */
    public function deleteCase(string $epid)
    {
        $ecol = $this->fieldLike('epid #')->col;
        $formulas = array_filter($this->fields, fn (Field $f) => $f->kind === 'formula' && $f->template);

        return $this->write(function (Worksheet $ws) use ($ecol, $formulas, $epid) {
            $last = self::lastDataRow($ws, $this->headerRow, count($this->fields));
            $row = null;
            for ($r = $this->headerRow + 1; $r <= $last; $r++) {
                if (strtoupper(trim(Text::str(Xl::at($ws, $r, $ecol) ?? ''))) === $epid) {
                    $row = $r;
                    break;
                }
            }
            if ($row === null) {
                throw new \InvalidArgumentException("There is no case $epid.");
            }
            $ws->removeRow($row);
            for ($r = $row; $r < $last; $r++) {
                foreach ($formulas as $f) {
                    $ws->getCell([$f->col, $r])->setValue($this->formulaFor($f->template, $this->lastRow, $r));
                }
            }

            return $row;
        });
    }

    /** Change the workbook: a backup copy first (.bak.xlsx), then load, edit, save and read it again. */
    public function write(callable $edit)
    {
        $bak = preg_replace('/\.xlsx$/i', '', $this->path).'.bak.xlsx';
        if (! @copy($this->path, $bak) || ! is_writable($this->path)) {
            throw new \RuntimeException(basename($this->path).' could not be written. Check it is not open or read-only.');
        }
        $wb = self::load($this->path);
        $ws = $wb->getActiveSheet();
        $result = $edit($ws);
        (new Xlsx($wb))->save($this->path);
        $wb->disconnectWorksheets();
        $this->reload();

        return $result;
    }

    public function append(array $values)
    {
        return $this->write(fn (Worksheet $ws) => $this->appendRow($ws, $values));
    }

    private function appendRow(Worksheet $ws, array $values): int
    {
        $src = self::lastDataRow($ws, $this->headerRow, count($this->fields));
        $row = $src + 1;
        foreach ($this->fields as $f) {
            $ws->duplicateStyle($ws->getStyle([$f->col, $src]), Coordinate::stringFromColumnIndex($f->col).$row);
            $cell = $ws->getCell([$f->col, $row]);
            if ($f->kind === 'formula') {
                $cell->setValue($this->formulaFor($f->template, $src, $row));
            } else {
                $v = $values[$f->col] ?? null;
                $cell->setValue(Xl::toCell($v));
                if ($f->kind === 'date' && (is_int($v) || is_float($v))) {
                    $ws->getStyle([$f->col, $row])->getNumberFormat()->setFormatCode('General');
                } elseif ($v instanceof DateTimeInterface && ! \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
                    $ws->getStyle([$f->col, $row])->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                }
            }
        }
        $this->extendRanges($ws, $src, $row);

        return $row;
    }

    /** Tables, conditional formats and dropdowns that end on the old last row now end on the new one. */
    private function extendRanges(Worksheet $ws, int $src, int $row): void
    {
        $move = fn (string $sqref) => implode(' ', array_map(
            fn ($part) => preg_replace('/(?<=[A-Z])'.$src.'$/', (string) $row, $part), preg_split('/\s+/', trim($sqref))));
        foreach ($ws->getTableCollection() as $table) {
            [$c1, $r1, $c2, $r2] = Xl::ranges($table->getRange())[0];
            if ($r2 === $src) {
                $table->setRange(Coordinate::stringFromColumnIndex($c1).$r1.':'.Coordinate::stringFromColumnIndex($c2).$row);
            }
        }
        foreach ($ws->getConditionalStylesCollection() as $sqref => $styles) {
            $new = $move($sqref);
            if ($new !== $sqref) {
                $ws->removeConditionalStyles($sqref);
                $ws->setConditionalStyles($new, $styles);
            }
        }
        foreach ($ws->getDataValidationCollection() as $sqref => $dv) {
            $new = $move($sqref);
            if ($new !== $sqref) {
                $ws->setDataValidation($sqref, null);
                $copy = clone $dv;
                $copy->setSqref($new);
                $ws->setDataValidation($new, $copy);
            }
        }
    }

    private function setCells(Worksheet $ws, string $epid, array $changes): ?int
    {
        $ecol = $this->fieldLike('epid #')->col;
        $max = $ws->getHighestRow();
        for ($r = $this->headerRow + 1; $r <= $max; $r++) {
            if (strtoupper(trim(Text::str(Xl::at($ws, $r, $ecol) ?? ''))) !== $epid) {
                continue;
            }
            foreach ($changes as $col => $v) {
                $f = $this->fields[$col - 1];
                $cell = $ws->getCell([$col, $r]);
                $cell->setValue(Xl::toCell($v));
                if ($f->kind === 'date' && (is_int($v) || is_float($v))) {
                    $ws->getStyle([$col, $r])->getNumberFormat()->setFormatCode('General');
                } elseif ($v instanceof DateTimeInterface && ! \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
                    $fmt = $f->numberFormat !== 'General' ? $f->numberFormat : 'dd/mm/yyyy';
                    $ws->getStyle([$col, $r])->getNumberFormat()->setFormatCode($fmt);
                }
            }

            return $r;
        }

        return null;
    }

    /**
     * Compare an uploaded table with the register, matching rows by EPID #. Nothing is written. With a region, only
     * that region's cases are updated or added; other rows are listed as problems.
     */
    public function planImport(array $header, array $rows, bool $addNew = false, ?string $region = null): array
    {
        $districtF = $this->fieldLike('district or');
        $byKey = [];
        foreach ($this->fields as $f) {
            $byKey[Text::key($f->label)] ??= $f;
        }
        $cols = [];
        $unknown = [];
        $ignored = [];
        foreach ($header as $i => $h) {
            if (Text::blank($h)) {
                continue;
            }
            $f = $byKey[Text::key($h)] ?? null;
            if (! $f) {
                $unknown[] = Text::clean($h);
            } elseif ($f->kind === 'formula') {
                $ignored[] = $f->label;
            } elseif (! in_array($f, $cols, true)) {
                $cols[$i] = $f;
            }
        }
        $epidF = $this->fieldLike('epid #');
        $snF = $this->snField();
        $nameF = $this->fieldLike('patient name');
        $epidI = array_search($epidF, $cols, true);
        if ($epidI === false) {
            throw new \InvalidArgumentException('The file needs an EPID # column so rows can be matched to cases.');
        }
        $current = $this->epids();
        $plan = ['rows' => 0, 'matched' => 0, 'changes' => [], 'adds' => [], 'unmatched' => [], 'errors' => [],
            'ignored' => $ignored, 'unknown' => $unknown, '_updates' => [], '_adds' => []];
        $seen = [];
        $nextSn = $this->nextSn();
        foreach ($rows as $row) {
            $row = array_pad(array_values($row), count($header), null);
            if (! array_filter($row, fn ($v) => ! Text::blank($v))) {
                continue;
            }
            $plan['rows']++;
            $epid = Text::blank($row[$epidI]) ? '' : strtoupper(Text::clean($row[$epidI]));
            if ($epid === '') {
                $plan['errors'][] = ['epid' => '—', 'field' => $epidF->label, 'msg' => 'Row has no EPID #, so it was skipped.'];

                continue;
            }
            if (isset($seen[$epid])) {
                $plan['errors'][] = ['epid' => $epid, 'field' => $epidF->label, 'msg' => 'Appears more than once in the file. Only the first row is used.'];

                continue;
            }
            $seen[$epid] = true;
            $old = $current[$epid] ?? null;
            if ($old !== null && $districtF && ! $this->inRegion($old[$districtF->col - 1], $region)) {
                $plan['errors'][] = ['epid' => $epid, 'field' => $districtF->label,
                    'msg' => "A case from {$old[$districtF->col - 1]}, outside $region Region, so it was skipped."];
            } elseif ($old !== null) {
                $plan['matched']++;
                $cells = [];
                $upd = [];
                foreach ($cols as $i => $f) {
                    if ($f === $epidF || $f === $snF) {
                        continue;
                    }
                    [$v, $err] = $this->convert($f, $row[$i]);
                    if ($err) {
                        $plan['errors'][] = ['epid' => $epid, 'field' => $f->label, 'msg' => ucfirst($err)];
                    } elseif ($v !== null && ! Text::same($old[$f->col - 1], $v)) {
                        $upd[$f->col] = $v;
                        $cells[] = ['field' => $f->label, 'old' => Text::show($old[$f->col - 1]), 'new' => Text::show($v)];
                    }
                }
                if ($upd) {
                    $plan['_updates'][$epid] = $upd;
                    $plan['changes'][] = ['epid' => $epid, 'name' => $nameF ? Text::show($old[$nameF->col - 1]) : '', 'cells' => $cells];
                }
            } elseif ($addNew) {
                $raw = [];
                foreach ($cols as $i => $f) {
                    $raw[$f->col] = $row[$i];
                }
                if ($snF && Text::blank($raw[$snF->col] ?? null)) {
                    $raw[$snF->col] = $nextSn;
                }
                [$values, $errs] = $this->prepareNew($raw);
                if ($e = $this->districtError($values, $region)) {
                    $errs[] = $e;
                }
                if ($errs) {
                    foreach ($errs as [, $m]) {
                        $plan['errors'][] = ['epid' => $epid, 'field' => 'New case', 'msg' => $m];
                    }
                } else {
                    $plan['_adds'][] = $values;
                    $plan['adds'][] = ['epid' => $epid, 'name' => $nameF ? Text::show($values[$nameF->col] ?? null) : ''];
                    $nextSn++;
                }
            } else {
                $plan['unmatched'][] = $epid;
            }
        }
        $plan['cellCount'] = array_sum(array_map(fn ($c) => count($c['cells']), $plan['changes']));

        return $plan;
    }

    public function applyImport(array $plan): array
    {
        if (! $plan['_updates'] && ! $plan['_adds']) {
            return [];
        }

        return $this->write(function (Worksheet $ws) use ($plan) {
            foreach ($plan['_updates'] as $epid => $upd) {
                $this->setCells($ws, (string) $epid, $upd);
            }

            return array_map(fn ($v) => $this->appendRow($ws, $v), $plan['_adds']);
        });
    }

    /** Current cases (entry columns only; with a region, its cases only) as a workbook people can edit and upload again. */
    public function exportXlsx(?string $region = null): string
    {
        $fields = array_values(array_filter($this->fields, fn (Field $f) => $f->kind !== 'formula'));
        $districtF = $this->fieldLike('district or');
        $wb = new Spreadsheet;
        $ws = $wb->getActiveSheet();
        $ws->setTitle('Cases');
        foreach ($fields as $i => $f) {
            $ws->getCell([$i + 1, 1])->setValue($f->label);
            $ws->getStyle([$i + 1, 1])->getFont()->setBold(true);
        }
        $r = 2;
        foreach ($this->existing as $row) {
            if ($districtF && ! $this->inRegion($row[$districtF->col - 1], $region)) {
                continue;
            }
            foreach ($fields as $i => $f) {
                $v = $row[$f->col - 1];
                $ws->getCell([$i + 1, $r])->setValue(Xl::toCell($v));
                if ($v instanceof DateTimeInterface) {
                    $ws->getStyle([$i + 1, $r])->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                }
            }
            $r++;
        }
        foreach ($fields as $i => $f) {
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth(max(10, min(40, mb_strlen($f->label) + 4)));
        }
        $ws->freezePane('C2');

        return self::bytes($wb);
    }

    public static function bytes(Spreadsheet $wb): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xl');
        (new Xlsx($wb))->save($tmp);
        $wb->disconnectWorksheets();
        $body = file_get_contents($tmp);
        @unlink($tmp);

        return $body;
    }

    /** Header row and data rows from an uploaded .xlsx or .csv file. */
    public static function readTable(string $name, string $data): array
    {
        $suffix = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($suffix, ['xlsx', 'xlsm'], true)) {
            $tmp = tempnam(sys_get_temp_dir(), 'up');
            file_put_contents($tmp, $data);
            try {
                $wb = IOFactory::createReader('Xlsx')->load($tmp);
            } catch (\Throwable) {
                throw new \InvalidArgumentException('That file could not be opened as an Excel workbook.');
            } finally {
                @unlink($tmp);
            }
            $rows = Xl::rows($wb->getActiveSheet(), null, true);
            $wb->disconnectWorksheets();
        } elseif ($suffix === 'csv') {
            $rows = self::readCsv($data);
        } elseif ($suffix === 'xls') {
            throw new \InvalidArgumentException('Old .xls files are not supported. Save it as .xlsx or .csv in Excel first.');
        } else {
            throw new \InvalidArgumentException('Upload an .xlsx or .csv file.');
        }
        foreach (array_slice($rows, 0, 10) as $i => $r) {
            foreach ($r as $c) {
                if (Text::key($c) === 'epid') {
                    return [$rows[$i], array_slice($rows, $i + 1)];
                }
            }
        }
        throw new \InvalidArgumentException('No header row with an EPID # column was found in the first 10 rows.');
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
