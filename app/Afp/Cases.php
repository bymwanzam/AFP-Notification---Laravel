<?php

namespace App\Afp;

use App\Support\Branding;
use App\Support\OrgUnits;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * The AFP register's cases as the AFP page sees them: read from the workbook, limited to a region (or every region
 * for the national view), with each case's edit window when the signed-in account may still change it.
 */
class Cases
{
    // Upper East report rows, in the order of the regional summary sheet, keyed by the register's district code.
    // Other regions use their org unit districts.
    public const REPORT_DISTRICTS = [
        ['BAW', 'Bawku Municipal'], ['BKW', 'Bawku West'], ['BID', 'Binduri'], ['BOE', 'Bolgatanga East'],
        ['BOG', 'Bolgatanga Municipal'], ['BON', 'Bongo Municipal'], ['BUI', 'Builsa North Municipal'],
        ['BUS', 'Builsa South'], ['GAR', 'Garu'], ['KND', 'Kassena-Nankana Municipal'], ['KNW', 'Kassena-Nankana West'],
        ['NAB', 'Nabdam'], ['PUS', 'Pusiga'], ['TAL', 'Talensi'], ['TEP', 'Tempane'],
    ];

    public const FIELDS = [
        'sn' => 'sn', 'epid' => 'epid #', 'name' => 'patient name', 'sex' => 'patient sex',
        'dob' => 'date of birth', 'district' => 'reporting district', 'week' => 'epid. week',
        'onset' => 'onset', 'outcome' => 'outcome', 'report' => 'report availability',
        'reportDate' => 'date report received', 'lab' => 'laboratory feedback', 'remarks' => 'remarks', 'code' => 'epid. code',
    ];

    public static function registerPath(): string
    {
        return config('surveillance.afp_register') ?: rtrim(config('surveillance.data_dir'), '/\\').'/AFP nofitication.xlsx';
    }

    private static function low($v): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $v === null ? '' : Text::str($v))));
    }

    public static function loadCases(string $xlsx): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $wb = $reader->load($xlsx);
        $rows = Xl::rows($wb->getActiveSheet());
        $wb->disconnectWorksheets();
        $hdr = null;
        foreach (array_slice($rows, 0, 10) as $i => $r) {
            foreach ($r as $c) {
                if (str_contains(self::low($c), 'epid #')) {
                    $hdr = $i;
                    break 2;
                }
            }
        }
        if ($hdr === null) {
            throw new \RuntimeException('No header row with an EPID # column in the register.');
        }
        $headers = array_map([self::class, 'low'], $rows[$hdr]);
        $idx = [];
        foreach (self::FIELDS as $k => $t) {
            $idx[$k] = null;
            foreach ($headers as $i => $h) {
                if (str_contains($h, $t)) {
                    $idx[$k] = $i;
                    break;
                }
            }
        }
        $cases = [];
        foreach (array_slice($rows, $hdr + 1) as $r) {
            if ($idx['epid'] !== null && ! empty($r[$idx['epid']])) {
                $c = [];
                foreach ($idx as $k => $i) {
                    $c[$k] = $i !== null && $i < count($r) ? Text::plain($r[$i]) : null;
                }
                $cases[] = $c;
            }
        }

        return $cases;
    }

    public static function reportDistricts(array $unit): array
    {
        return $unit['name'] === OrgUnits::DEFAULT_REGION ? self::REPORT_DISTRICTS : array_map(fn ($d) => [$d[0], $d[1]], $unit['districts']);
    }

    /** Whether a case's reporting district is in the region; cases with no known district stay with Upper East. */
    public static function inRegion(array $case, array $unit): bool
    {
        return OrgUnits::isNational($unit) || OrgUnits::caseRegion($case['district'] ?? null)['name'] === $unit['name'];
    }

    /** The unit's cases. In the national view every case, with its code set to its region's code. */
    public static function regionCases(string $xlsx, array $unit): array
    {
        $cases = array_values(array_filter(self::loadCases($xlsx), fn ($c) => self::inRegion($c, $unit)));
        if (OrgUnits::isNational($unit)) {
            foreach ($cases as &$c) {
                $r = OrgUnits::caseRegion($c['district'] ?? null);
                $c['code'] = $r['code'];
                $c['region'] = $r['name'];
            }
        }

        return $cases;
    }

    /** $editable: [EPID => until] for the cases the signed-in account may still edit or delete. */
    public static function snapshot(?string $xlsx = null, bool $live = true, ?array $unit = null, array $editable = []): array
    {
        $xlsx ??= self::registerPath();
        $unit ??= OrgUnits::currentRegion();
        $cases = self::regionCases($xlsx, $unit);
        foreach ($cases as &$c) {
            $until = $editable[strtoupper(trim((string) ($c['epid'] ?? '')))] ?? null;
            if ($until) {
                $c['editUntil'] = $until;
            }
        }

        return ['generated' => date('Y-m-d\TH:i:s'), 'source' => basename($xlsx),
            'live' => $live, 'appUrl' => '/', 'region' => Branding::unitLabel($unit), 'regionName' => $unit['name'],
            'national' => OrgUnits::isNational($unit),
            'org' => Branding::orgFor($unit), 'districts' => self::reportDistricts($unit), 'cases' => $cases];
    }
}
