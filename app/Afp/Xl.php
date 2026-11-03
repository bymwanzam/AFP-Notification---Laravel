<?php

namespace App\Afp;

use DateTimeImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Cell values the way the register code expects them: null, string, int, float or a date (DateTimeImmutable);
 * formulas as their "=..." text. Dates are numbers in a date format in the workbook.
 */
class Xl
{
    public static function value(?Cell $cell, bool $calculated = false)
    {
        if (! $cell) {
            return null;
        }
        $v = $calculated && $cell->isFormula() ? self::calculated($cell) : $cell->getValue();
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $v = $v->getPlainText();
        }
        if ($v === '' ) {
            return null;
        }
        if ((is_int($v) || is_float($v)) && ! $cell->isFormula() && Date::isDateTime($cell)) {
            return DateTimeImmutable::createFromMutable(Date::excelToDateTimeObject($v));
        }
        if (is_float($v) && floor($v) == $v && abs($v) < PHP_INT_MAX) {
            return $v;  // kept as float: callers decide (Python's openpyxl keeps 3.0 as a float too)
        }

        return $v;
    }

    private static function calculated(Cell $cell)
    {
        try {
            return $cell->getOldCalculatedValue() ?? $cell->getCalculatedValue();
        } catch (\Throwable) {
            return $cell->getOldCalculatedValue();
        }
    }

    public static function at(Worksheet $ws, int $row, int $col, bool $calculated = false)
    {
        return $ws->cellExists([$col, $row]) ? self::value($ws->getCell([$col, $row]), $calculated) : null;
    }

    /** Rows (1-based) of values, columns 1..$ncols. */
    public static function rows(Worksheet $ws, ?int $ncols = null, bool $calculated = false): array
    {
        $ncols ??= Coordinate::columnIndexFromString($ws->getHighestDataColumn());
        $out = [];
        $max = $ws->getHighestDataRow();
        for ($r = 1; $r <= $max; $r++) {
            $row = [];
            for ($c = 1; $c <= $ncols; $c++) {
                $row[] = self::at($ws, $r, $c, $calculated);
            }
            $out[] = $row;
        }

        return $out;
    }

    public static function isDate($v): bool
    {
        return $v instanceof DateTimeInterface;
    }

    /** A PHP value as what goes into a cell (dates as Excel serial numbers). */
    public static function toCell($v)
    {
        return $v instanceof DateTimeInterface ? Date::PHPToExcel($v) : $v;
    }

    /** [minCol, minRow, maxCol, maxRow] of each part of "A1:B5 C3". */
    public static function ranges(string $sqref): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($sqref)) as $part) {
            if ($part === '') {
                continue;
            }
            [$a, $b] = array_pad(explode(':', $part), 2, null);
            $b ??= $a;
            [$c1, $r1] = Coordinate::indexesFromString(str_replace('$', '', $a));
            [$c2, $r2] = Coordinate::indexesFromString(str_replace('$', '', $b));
            $out[] = [$c1, $r1, $c2, $r2];
        }

        return $out;
    }

    public static function covers(string $sqref, int $col, int $row): bool
    {
        foreach (self::ranges($sqref) as [$c1, $r1, $c2, $r2]) {
            if ($c1 <= $col && $col <= $c2 && $r1 <= $row && $row <= $r2) {
                return true;
            }
        }

        return false;
    }
}
