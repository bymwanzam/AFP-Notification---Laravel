<?php

namespace App\Afp;

use App\Support\Branding;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/** The AFP district/region report as a print-ready Excel workbook: title, bordered table, SUM totals, source note. */
class Report
{
    public static function xlsx(array $rep, array $unit): string
    {
        $wb = new Spreadsheet;
        $ws = $wb->getActiveSheet();
        $ws->setTitle('Report');
        $widths = [6, 38, 24, 28];
        foreach (['A', 'B', 'C', 'D'] as $i => $col) {
            $ws->getColumnDimension($col)->setWidth($widths[$i]);
        }
        $box = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]]];
        $tnr = fn (bool $bold) => ['font' => ['name' => 'Times New Roman', 'size' => 12, 'bold' => $bold]];
        $align = fn (int $c, string $v = Alignment::VERTICAL_BOTTOM, bool $wrap = false) => ['alignment' => [
            'horizontal' => $c > 2 ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_LEFT, 'vertical' => $v, 'wrapText' => $wrap]];
        $t = 1;
        $logo = Branding::logoFor($unit);
        if (is_file($logo)) {
            // Letterhead: logo centred over the table in its own row.
            [$w, $h] = getimagesize($logo);
            $height = 84;
            $width = (int) round($height * $w / $h);
            $tablePx = array_sum(array_map(fn ($x) => $x * 7 + 5, $widths));
            $left = intdiv($tablePx - $width, 2) - ($widths[0] * 7 + 5);
            $d = new Drawing;
            $d->setPath($logo);
            $d->setResizeProportional(false);
            $d->setWidth($width);
            $d->setHeight($height);
            $d->setCoordinates('B1');
            $d->setOffsetX(max($left, 0));
            $d->setOffsetY(6);
            $d->setWorksheet($ws);
            $ws->getRowDimension(1)->setRowHeight(72);
            $t = 2;
        }
        $ws->mergeCells([1, $t, 4, $t]);
        $ws->getCell([1, $t])->setValue($rep['title'] ?? '');
        $ws->getStyle([1, $t])->applyFromArray(['font' => ['name' => 'Times New Roman', 'size' => 14, 'bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true]]);
        $ws->getRowDimension($t)->setRowHeight(40);
        foreach (array_values($rep['header'] ?? []) as $i => $h) {
            $c = $i + 1;
            $ws->getCell([$c, $t + 1])->setValue($h);
            $ws->getStyle([$c, $t + 1])->applyFromArray($tnr(true) + $box + $align($c, Alignment::VERTICAL_BOTTOM, true));
        }
        $ws->getRowDimension($t + 1)->setRowHeight(34);
        $first = $r = $t + 2;
        foreach ($rep['rows'] ?? [] as $row) {
            foreach (array_values($row) as $i => $v) {
                $c = $i + 1;
                $ws->getCell([$c, $r])->setValue($v === '' ? null : $v);
                $ws->getStyle([$c, $r])->applyFromArray($tnr($c <= 2) + $box + $align($c));
            }
            $ws->getRowDimension($r)->setRowHeight(22);
            $r++;
        }
        $ws->mergeCells([1, $r, 2, $r]);
        $ws->getCell([1, $r])->setValue((string) (($rep['totalLabel'] ?? '') ?: 'REGIONAL TOTAL'));
        $ws->getCell([3, $r])->setValue('=SUM(C'.$first.':C'.($r - 1).')');
        $ws->getCell([4, $r])->setValue('=SUM(D'.$first.':D'.($r - 1).')');
        for ($c = 1; $c <= 4; $c++) {
            $ws->getStyle([$c, $r])->applyFromArray($tnr(true) + $box + $align($c));
        }
        foreach ([2 => [$rep['foot'] ?? '', 48, true], 3 => [$rep['rights'] ?? '', 18, false]] as $k => [$text, $height, $italic]) {
            $ws->mergeCells([1, $r + $k, 4, $r + $k]);
            $ws->getCell([1, $r + $k])->setValue($text);
            $ws->getStyle([1, $r + $k])->applyFromArray(['font' => ['name' => 'Times New Roman', 'size' => 10, 'italic' => $italic,
                'color' => ['rgb' => $italic ? '333333' : '555555']],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP]]);
            $ws->getRowDimension($r + $k)->setRowHeight($height);
        }
        $ws->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)->setFitToHeight(0)->setPrintArea('A1:D'.($r + 3));
        // Background colour picked on the report page; white leaves the cells unfilled.
        $paper = (string) ($rep['paper'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $paper) && strtolower($paper) !== '#ffffff') {
            $ws->getStyle('A1:D'.($r + 3))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(strtoupper(substr($paper, 1)));
        }

        return Register::bytes($wb);
    }
}
