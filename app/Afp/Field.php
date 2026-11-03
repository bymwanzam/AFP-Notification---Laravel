<?php

namespace App\Afp;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** One column of the register: its label and what it holds (formula, dropdown, date, number or text). */
class Field
{
    public int $col;
    public string $letter;
    public string $label;
    public string $kind;
    public ?string $template = null;
    public array $options = [];
    public bool $strict = false;
    public bool $allowAge;
    public bool $required;
    public string $numberFormat;

    public function __construct(Worksheet $ws, int $col, int $headerRow, int $lastRow)
    {
        $this->col = $col;
        $this->letter = Coordinate::stringFromColumnIndex($col);
        $this->label = explode(' (', Text::clean(Xl::at($ws, $headerRow, $col)))[0];
        $values = [];
        for ($r = $headerRow + 1; $r <= $lastRow; $r++) {
            $values[] = Xl::at($ws, $r, $col);
        }
        $filled = array_values(array_filter($values, fn ($v) => $v !== null && $v !== ''));
        $this->allowAge = str_contains(strtolower($this->label), 'age');
        if ($filled && ! array_filter($filled, fn ($v) => ! (is_string($v) && str_starts_with($v, '=')))) {
            $this->kind = 'formula';
            $this->template = end($filled);
        } else {
            $options = Register::dvListFor($ws, $this->letter, $lastRow + 1) ?? Register::dvListFor($ws, $this->letter, $lastRow);
            $strs = array_values(array_filter($filled, 'is_string'));
            $dates = count(array_filter($filled, [Xl::class, 'isDate']));
            if ($options) {
                [$this->kind, $this->options, $this->strict] = ['dropdown', $options, true];
            } elseif ($dates > count($filled) / 2) {
                $this->kind = 'date';
            } elseif ($filled && ! array_filter($filled, fn ($v) => ! (is_int($v) || is_float($v)))) {
                $this->kind = 'number';
            } elseif ($strs && count(array_unique($strs)) <= 5 && count($strs) >= 3 && max(array_map('mb_strlen', $strs)) <= 30) {
                $opts = array_values(array_unique(array_map('trim', $strs)));
                sort($opts, SORT_STRING);
                if ($opts === ['Yes']) {
                    $opts = ['Yes', 'No'];
                }
                [$this->kind, $this->options, $this->strict] = ['dropdown', $opts, false];
            } else {
                $this->kind = 'text';
            }
        }
        $this->required = $this->kind !== 'formula' && $values && count($filled) === count($values);
        $this->numberFormat = $ws->getStyle([$col, $lastRow])->getNumberFormat()->getFormatCode();
    }
}
