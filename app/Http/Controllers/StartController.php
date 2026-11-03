<?php

namespace App\Http\Controllers;

use App\Afp\Cases;
use App\Idsr\Store;
use App\Services\Accounts;
use App\Support\Access;
use App\Support\Branding;
use App\Support\OrgUnits;
use Illuminate\Http\Request;

/**
 * Start page: the signed-in account's region, then open a module. Support and national administrators pick a
 * region, or Ghana (all regions), for this sign-in.
 */
class StartController extends Controller
{
    /** The regions with their districts, AFP cases in the register and IDSR weeks on file. */
    private function regionsSnapshot(): array
    {
        $afp = [];
        try {
            foreach (Cases::loadCases(Cases::registerPath()) as $c) {
                $name = (OrgUnits::districtRegion($c['district'] ?? null)[0] ?? ['name' => OrgUnits::DEFAULT_REGION])['name'];
                $afp[$name] = ($afp[$name] ?? 0) + 1;
            }
        } catch (\Throwable) {
        }
        $weeks = [];
        if (class_exists(Store::class)) {
            foreach (Store::regionsOnFile() as $name) {
                try {
                    if ($u = OrgUnits::regionOf($name)) {
                        $weeks[$u['name']] = count(Store::for($u['name'])->weeks);
                    }
                } catch (\Throwable) {
                }
            }
        }

        return array_map(fn ($r) => ['name' => $r['name'], 'code' => $r['code'], 'districts' => $r['districts'],
            'afp' => $afp[$r['name']] ?? 0, 'weeks' => $weeks[$r['name']] ?? 0], OrgUnits::all());
    }

    public function start(Request $request)
    {
        $here = Access::unit($request);
        $regions = $this->regionsSnapshot();
        if (Access::seesAll($request)) {
            $g = OrgUnits::nationalUnit();
            $nationalWeeks = 0;
            if (class_exists(Store::class)) {
                try {
                    $nationalWeeks = count(Store::for(OrgUnits::NATIONAL)->weeks);
                } catch (\Throwable) {
                }
            }
            $ghana = ['name' => $g['name'], 'code' => $g['code'], 'districts' => $g['districts'], 'national' => true,
                'afp' => array_sum(array_column($regions, 'afp')), 'weeks' => $nationalWeeks];
            $snap = ['selected' => $here['name'], 'regions' => array_merge([$ghana], $regions)];
        } else {
            $snap = ['selected' => $here['name'], 'locked' => true,
                'regions' => array_values(array_filter($regions, fn ($r) => $r['name'] === $here['name']))];
        }
        $data = $snap + ['next' => (string) $request->query('next', ''), 'support' => Access::isSupport($request),
            'profile' => Accounts::profile($request->user())];

        return view('core.start', ['logo' => Branding::dataUri(Branding::emblem()), 'data' => $data]);
    }

    /** Support and national administrators: the region to work with for this sign-in. */
    public function region(Request $request)
    {
        try {
            $name = (string) (Access::body($request)['region'] ?? '');
        } catch (\InvalidArgumentException) {
            $name = '';
        }
        if (Access::seesAll($request)) {
            $asked = strtolower(trim($name)) === strtolower(OrgUnits::NATIONAL) ? OrgUnits::nationalUnit() : OrgUnits::regionOf($name);
            if (! $asked) {
                return Access::error(400, 'Choose a region.');
            }
            $request->session()->put('view', $asked['name']);

            return Access::ok(['region' => $asked['name']]);
        }
        $asked = OrgUnits::regionOf($name);
        $mine = Access::unit($request);
        if (! $asked || $asked['name'] !== $mine['name']) {
            return Access::error(403, "Your account is for {$mine['name']} Region only.");
        }

        return Access::ok(['region' => $asked['name']]);
    }
}
