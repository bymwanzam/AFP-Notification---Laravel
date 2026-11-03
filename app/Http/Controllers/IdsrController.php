<?php

namespace App\Http\Controllers;

use App\Idsr\Bulletin;
use App\Idsr\Dhims2;
use App\Idsr\Store;
use App\Support\Access;
use App\Support\Branding;
use App\Support\OrgUnits;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The Weekly IDSR module: data entry, upload, fetch from DHIMS2 and the weekly epidemiological bulletin.
 *
 * Each region has its own store (Store::for); Ghana has its own too, whose rows are the regions. A page names its
 * region on every call (?region=), and it must be the account's own; support and national administrators get the
 * region they picked on the start page.
 */
class IdsrController extends Controller
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** The request's region store, or null when the call named another region than the account's own. */
    private function regionStore(Request $request): ?Store
    {
        $here = Access::unit($request);
        $asked = (string) $request->query('region', '');
        if (! Access::seesAll($request) && $asked !== '' && (OrgUnits::regionOf($asked)['name'] ?? null) !== $here['name']) {
            return null;
        }

        return Store::for($here['name']);
    }

    private static function yearWeek(Request $request): array
    {
        try {
            return Store::checkWeek($request->query('year', ''), $request->query('week', ''));
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Choose a week.');
        }
    }

    /** A call on the region's store: its errors come back as JSON. */
    private function call(Request $request, callable $fn)
    {
        try {
            $store = $this->regionStore($request);
            if (! $store) {
                return Access::error(403, 'Your account is for '.Access::unit($request)['name'].' Region only.');
            }

            return $fn($store);
        } catch (InvalidArgumentException $e) {
            return Access::error(400, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return Access::error(500, ($request->isMethod('get') ? 'Could not read the IDSR data: ' : 'Save failed: ').$e->getMessage());
        }
    }

    private function attachment(string $body, string $type, string $name)
    {
        return response($body, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    private static function public(array $plan): array
    {
        return array_filter($plan, fn ($k) => ! str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);
    }

    // ---------- pages and reads ----------
    public function page(Request $request)
    {
        $here = Access::unit($request);
        try {
            $store = $this->regionStore($request);
            if (! $store) {
                return response("Your account is for {$here['name']} Region only.", 403, ['Content-Type' => 'text/plain']);
            }
            $data = Bulletin::snapshot($store);
        } catch (\Throwable $e) {
            report($e);

            return response('Could not read the IDSR data: '.$e->getMessage(), 500, ['Content-Type' => 'text/plain']);
        }

        return view('idsr.idsr', Branding::letterhead($here) + [
            'emblem' => Branding::dataUri(Branding::emblem()), 'rhd' => Branding::dataUri(Branding::rhd()), 'data' => $data]);
    }

    public function data(Request $request)
    {
        return $this->call($request, fn (Store $s) => Access::ok(Bulletin::snapshot($s)));
    }

    public function formXlsx(Request $request)
    {
        return $this->call($request, function (Store $s) use ($request) {
            [$y, $w] = self::yearWeek($request);

            return $this->attachment($s->formXlsx($y, $w), self::XLSX, sprintf('IDSR weekly form %s wk%03d %d.xlsx', $s->code, $w, $y));
        });
    }

    public function weekCsv(Request $request)
    {
        return $this->call($request, function (Store $s) use ($request) {
            [$y, $w] = self::yearWeek($request);

            return $this->attachment($s->weekCsv($y, $w), 'text/csv; charset=utf-8', sprintf('IDSR week %03d %d %s.csv', $w, $y, $s->code));
        });
    }

    public function storeXlsx(Request $request)
    {
        return $this->call($request, function (Store $s) {
            if (! is_file($s->path)) {
                throw new InvalidArgumentException('No weeks have been saved for this region yet.');
            }

            return $this->attachment(file_get_contents($s->path), self::XLSX, basename($s->path));
        });
    }

    // ---------- writes ----------
    public function saveWeek(Request $request)
    {
        return $this->call($request, function (Store $s) use ($request) {
            $d = Access::body($request);
            $s->putWeek($d['year'] ?? null, $d['week'] ?? null, $d['meta'] ?? null, $d['counts'] ?? null);

            return Access::ok(['data' => Bulletin::snapshot($s)]);
        });
    }

    public function deleteWeek(Request $request)
    {
        return $this->call($request, function (Store $s) use ($request) {
            if (! Access::isSupport($request)) {
                return Access::error(403, 'Only the support account can delete a week.');
            }
            $d = Access::body($request);
            $s->deleteWeek($d['year'] ?? null, $d['week'] ?? null);

            return Access::ok(['data' => Bulletin::snapshot($s)]);
        });
    }

    public function importFile(Request $request)
    {
        return $this->call($request, function (Store $s) use ($request) {
            $plan = $s->planImport(...Store::readUpload((string) $request->query('name', 'upload.csv'), $request->getContent()));
            $out = self::public($plan);
            if ($request->query('apply') === '1') {
                $s->applyImport($plan);
                $out['data'] = Bulletin::snapshot($s);
            }

            return Access::ok($out);
        });
    }

    public function saveSettings(Request $request)
    {
        return $this->call($request, function (Store $s) use ($request) {
            $s->setSettings(Access::body($request));

            return Access::ok(['data' => Bulletin::snapshot($s)]);
        });
    }

    public function image(Request $request)
    {
        return $this->call($request, function (Store $s) use ($request) {
            Bulletin::setImage((string) $request->query('slot', ''), $request->getContent(), $s->code);

            return Access::ok(['data' => Bulletin::snapshot($s)]);
        });
    }

    // ---------- DHIMS2 ----------
    public function dhims2Config(Request $request)
    {
        return $this->call($request, fn () => Access::ok(Dhims2::publicConfig()));
    }

    public function dhims2Datasets(Request $request)
    {
        return $this->call($request, fn () => Access::ok(['dataSets' => Dhims2::dataSets()]));
    }

    public function dhims2Elements(Request $request)
    {
        return $this->call($request, fn () => Access::ok(Dhims2::elements((string) $request->query('ds', ''))));
    }

    public function dhims2Connect(Request $request)
    {
        return $this->call($request, function () use ($request) {
            $d = Access::body($request);
            $cfg = Dhims2::connect($d['url'] ?? null, $d['token'] ?? null, ! empty($d['remember']));

            return Access::ok($cfg + ['dataSets' => Dhims2::dataSets()]);
        });
    }

    public function dhims2Forget(Request $request)
    {
        return $this->call($request, fn () => Access::ok(Dhims2::forget()));
    }

    public function dhims2Mapping(Request $request)
    {
        return $this->call($request, function () use ($request) {
            $d = Access::body($request);

            return Access::ok(['mapping' => (object) Dhims2::saveMapping($d['ds'] ?? null, $d['mapping'] ?? null)]);
        });
    }

    /** Fetches again on apply, the way an upload is sent again, so what is saved is what DHIMS2 has now. */
    public function dhims2Fetch(Request $request)
    {
        return $this->call($request, function (Store $s) use ($request) {
            $d = Access::body($request);
            $plan = Dhims2::fetch($s, $d['ds'] ?? null, $d['mapping'] ?? null, $d['year'] ?? null, $d['from'] ?? null, $d['to'] ?? null);
            $out = self::public($plan);
            if (! empty($d['apply'])) {
                $s->applyImport($plan);
                $out['data'] = Bulletin::snapshot($s);
            }

            return Access::ok($out);
        });
    }
}
