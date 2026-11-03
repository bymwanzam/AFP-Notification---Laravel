<?php

namespace App\Http\Controllers;

use App\Afp\Cases;
use App\Afp\Entries;
use App\Afp\Register;
use App\Afp\Report;
use App\Services\Sms;
use App\Support\Access;
use App\Support\Branding;
use App\Support\OrgUnits;
use Illuminate\Http\Request;

/**
 * The AFP 60-day follow-up module: alerts and case list, new-case entry, editing a case within 24 hours, update
 * from file, downloads, the district/region report and SMS to district officers. Everything works for the
 * request's region (Access::unit).
 */
class AfpController extends Controller
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private function path(): string
    {
        return Cases::registerPath();
    }

    /** The register's errors as JSON: a bad request (400) or anything else (500). */
    private function call(Request $request, callable $fn)
    {
        try {
            return $fn();
        } catch (\InvalidArgumentException $e) {
            return Access::error(400, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return Access::error(500, ($request->isMethod('get') ? 'Could not read the register: ' : 'Save failed: ').$e->getMessage());
        }
    }

    private function attachment(string $body, string $type, string $name)
    {
        return response($body, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    private function formErrors(array $errors)
    {
        return Access::error(400, 'Check the form.', ['errors' => array_map(fn ($e) => ['col' => $e[0], 'msg' => $e[1]], $errors)]);
    }

    private static function fields(array $d): array
    {
        $out = [];
        foreach ((array) ($d['fields'] ?? []) as $k => $v) {
            $out[(int) $k] = $v;
        }

        return $out;
    }

    // ---------- editing a case within 24 hours of entry: regional accounts only ----------
    /** [EPID => when its edit window closes] for the cases this account may still edit; "always" for support. */
    private function editable(Request $request, array $here): array
    {
        if (Access::isSupport($request)) {
            $out = [];
            foreach (Cases::regionCases($this->path(), $here) as $c) {
                if (! empty($c['epid'])) {
                    $out[strtoupper(trim((string) $c['epid']))] = 'always';
                }
            }

            return $out;
        }
        if (Access::seesAll($request) || OrgUnits::isNational($here)) {
            return [];
        }
        $out = [];
        $now = new \DateTimeImmutable;
        foreach (Entries::load($this->path()) as $epid => $e) {
            if (($e['region'] ?? null) === $here['name'] && ($until = Entries::until($e)) > $now) {
                $out[$epid] = $until->format('Y-m-d\TH:i');
            }
        }

        return $out;
    }

    /** Why this account may not edit or delete the case, or null. */
    private function editProblem(Request $request, string $epid, array $here): ?string
    {
        if (Access::isSupport($request)) {
            return isset((new Register($this->path()))->epids()[$epid]) ? null : "There is no case $epid.";
        }
        if (Access::seesAll($request)) {
            return 'Only regional accounts can edit or delete a case, within 24 hours of entering it.';
        }
        $e = Entries::load($this->path())[$epid] ?? null;
        if (! $e) {
            return "This case was not entered in this app, so it can't be edited or deleted here. Use Update from file to change it.";
        }
        if (($e['region'] ?? null) !== $here['name']) {
            return "$epid was entered for {$e['region']} Region.";
        }
        if (Entries::until($e) <= new \DateTimeImmutable) {
            return "$epid was entered more than 24 hours ago, so it can no longer be edited or deleted here. Use Update from file to change it.";
        }

        return null;
    }

    /** Note who entered new cases and when, for the 24-hour edit window. */
    private function logAdded(Request $request, Register $reg, array $rows): void
    {
        $ef = $reg->fieldLike('epid #');
        $df = $reg->fieldLike('district or');
        if (! $ef) {
            return;
        }
        $items = [];
        foreach ($rows as $v) {
            if (! empty($v[$ef->col])) {
                $items[] = [$v[$ef->col], OrgUnits::caseRegion($df ? ($v[$df->col] ?? null) : null)['name'], $request->user()->username];
            }
        }
        Entries::log($this->path(), $items);
    }

    private function snapshot(Request $request, array $here): array
    {
        $data = Cases::snapshot($this->path(), true, $here, $this->editable($request, $here));
        $sent = Sms::lastSent(array_column($data['cases'], 'epid'));
        foreach ($data['cases'] as &$c) {
            if ($s = $sent[strtoupper(trim((string) $c['epid']))] ?? null) {
                $c['sms'] = $s;
            }
        }
        $data['sms'] = Sms::configured();

        return $data;
    }

    // ---------- pages and reads ----------
    public function dashboard(Request $request)
    {
        $here = Access::unit($request);
        try {
            $data = $this->snapshot($request, $here);
        } catch (\Throwable $e) {
            report($e);

            return response('Could not read '.basename($this->path()).': '.$e->getMessage(), 500, ['Content-Type' => 'text/plain']);
        }

        return view('afp.dashboard', Branding::letterhead($here) + ['data' => $data]);
    }

    public function data(Request $request)
    {
        return $this->call($request, fn () => Access::ok($this->snapshot($request, Access::unit($request))));
    }

    /** The New case form offers only this region and its districts (in the Ghana view, every region). */
    public function schema(Request $request)
    {
        return $this->call($request, function () use ($request) {
            $here = Access::unit($request);
            $sc = (new Register($this->path()))->schema();
            if (! OrgUnits::isNational($here)) {
                $sc['regions'] = array_values(array_filter($sc['regions'], fn ($r) => $r['name'] === $here['name']));
                $sc['homeRegion'] = $here['name'];
            }

            return Access::ok($sc);
        });
    }

    public function nextEpid(Request $request)
    {
        return $this->call($request, function () use ($request) {
            $here = Access::unit($request);
            $region = OrgUnits::isNational($here) ? (string) $request->query('region', '') : $here['name'];

            return Access::ok(['epid' => (new Register($this->path()))->nextEpid((string) $request->query('code', ''), $region)]);
        });
    }

    public function case(Request $request)
    {
        return $this->call($request, function () use ($request) {
            $here = Access::unit($request);
            $epid = strtoupper(trim((string) $request->query('epid', '')));
            if ($problem = $this->editProblem($request, $epid, $here)) {
                return Access::error(403, $problem);
            }
            $reg = new Register($this->path());
            $entry = Entries::load($this->path())[$epid] ?? null;
            $fields = $reg->caseValues($epid);
            $df = $reg->fieldLike('district or');

            return Access::ok(['epid' => $epid, 'fields' => (object) $fields,
                'region' => OrgUnits::caseRegion($df ? ($fields[$df->col] ?? null) : null)['name'],
                'until' => Access::isSupport($request) || ! $entry ? null : Entries::until($entry)->format('Y-m-d\TH:i')]);
        });
    }

    public function registerXlsx(Request $request)
    {
        return $this->call($request, function () use ($request) {
            $here = Access::unit($request);
            $body = (new Register($this->path()))->exportXlsx(Access::scope($request));

            return $this->attachment($body, self::XLSX, "AFP cases for update - {$here['name']}.xlsx");
        });
    }

    // ---------- writes ----------
    public function newCase(Request $request)
    {
        return $this->call($request, function () use ($request) {
            $raw = self::fields(Access::body($request));
            $reg = new Register($this->path());
            [$values, $errors] = $reg->prepareNew($raw);
            if ($e = $reg->districtError($values, Access::scope($request))) {
                $errors[] = $e;
            }
            if ($errors) {
                return $this->formErrors($errors);
            }
            $row = $reg->append($values);
            $ef = $reg->fieldLike('epid #');
            $nf = $reg->fieldLike('patient name');
            $this->logAdded($request, $reg, [$values]);

            return Access::ok(['row' => $row, 'epid' => $ef ? ($values[$ef->col] ?? null) : null,
                'name' => $nf ? ($values[$nf->col] ?? null) : null]);
        });
    }

    public function importFile(Request $request)
    {
        return $this->call($request, function () use ($request) {
            [$header, $rows] = Register::readTable((string) $request->query('name', 'upload.csv'), $request->getContent());
            $reg = new Register($this->path());
            $plan = $reg->planImport($header, $rows, $request->query('add') === '1', Access::scope($request));
            if ($request->query('apply') === '1') {
                $plan['addedRows'] = $reg->applyImport($plan);
                $this->logAdded($request, $reg, $plan['_adds']);
            }

            return Access::ok(array_filter($plan, fn ($k) => ! str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY));
        });
    }

    public function updateCase(Request $request)
    {
        return $this->call($request, function () use ($request) {
            $here = Access::unit($request);
            $d = Access::body($request);
            $epid = strtoupper(trim((string) ($d['epid'] ?? '')));
            if ($problem = $this->editProblem($request, $epid, $here)) {
                return Access::error(403, $problem);
            }
            $reg = new Register($this->path());
            [$values, $errors] = $reg->prepareEdit($epid, self::fields($d));
            if ($e = $reg->districtError($values, Access::scope($request))) {
                $errors[] = $e;
            }
            if ($errors) {
                return $this->formErrors($errors);
            }
            $reg->updateCase($epid, $values);
            $newId = strtoupper(trim((string) ($values[$reg->fieldLike('epid #')->col] ?? '') ?: $epid));
            Entries::rename($this->path(), $epid, $newId);  // a corrected EPID # keeps its 24-hour window

            return Access::ok(['epid' => $newId]);
        });
    }

    public function deleteCase(Request $request)
    {
        return $this->call($request, function () use ($request) {
            $here = Access::unit($request);
            $epid = strtoupper(trim((string) (Access::body($request)['epid'] ?? '')));
            if ($problem = $this->editProblem($request, $epid, $here)) {
                return Access::error(403, $problem);
            }
            (new Register($this->path()))->deleteCase($epid);
            Entries::drop($this->path(), $epid);

            return Access::ok(['deleted' => $epid]);
        });
    }

    public function report(Request $request)
    {
        return $this->call($request, function () use ($request) {
            $rep = json_decode($request->getContent(), true) ?: [];

            return $this->attachment(Report::xlsx($rep, Access::unit($request)), self::XLSX, 'AFP cases by district.xlsx');
        });
    }
}
