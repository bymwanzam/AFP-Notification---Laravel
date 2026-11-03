<?php

namespace App\Afp;

use DateTimeImmutable;
use DateTimeInterface;

/** Small text and value helpers shared by the register code. */
class Text
{
    public const DATE_FORMATS = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'Y-m-d H:i:s', 'd/m/Y H:i'];

    /** Whitespace collapsed and trimmed. */
    public static function clean($text): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::str($text)));
    }

    /** A value as text the way the Python code printed it (3.5, 3, dates as yyyy-mm-dd hh:mm:ss). */
    public static function str($v): string
    {
        if ($v === null) {
            return 'None';
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }
        if (is_bool($v)) {
            return $v ? 'True' : 'False';
        }
        if (is_float($v)) {
            return floor($v) == $v ? number_format($v, 1, '.', '') : (string) $v;
        }

        return (string) $v;
    }

    /** Loose header match: 'Outcome \n(select ...)' and 'outcome' both become 'outcome'. */
    public static function key($header): string
    {
        $h = explode(' (', self::clean($header === null ? '' : $header))[0];

        return preg_replace('/[^a-z0-9]/', '', strtolower($h));
    }

    public static function blank($v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '');
    }

    /** How a value is shown in the import review. */
    public static function show($v): string
    {
        if (self::blank($v)) {
            return '(blank)';
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format('d/m/Y');
        }
        if (is_float($v) && floor($v) == $v) {
            return (string) (int) $v;
        }

        return self::clean($v);
    }

    public static function same($a, $b): bool
    {
        if ($a instanceof DateTimeInterface && $b instanceof DateTimeInterface) {
            return $a->format('Y-m-d') === $b->format('Y-m-d');
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }

        return strtolower(self::clean($a ?? '')) === strtolower(self::clean($b ?? ''));
    }

    public static function parseDate(string $text): ?DateTimeImmutable
    {
        $text = trim($text);
        foreach (self::DATE_FORMATS as $fmt) {
            $d = DateTimeImmutable::createFromFormat('!'.$fmt, $text);
            $err = DateTimeImmutable::getLastErrors();
            if ($d && (! $err || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
                return $d;
            }
        }

        return null;
    }

    /** A cell value as the case list shows it: dates as yyyy-mm-dd, whole floats as integers, blank text as null. */
    public static function plain($v)
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_string($v)) {
            $s = trim(preg_replace('/\s+/u', ' ', $v));

            return $s === '' ? null : $s;
        }
        if (is_float($v) && floor($v) == $v) {
            return (int) $v;
        }

        return $v;
    }
}
