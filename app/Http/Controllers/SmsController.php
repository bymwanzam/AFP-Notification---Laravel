<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\SmsMessage;
use App\Services\LetsFish;
use App\Services\Sms;
use App\Support\Access;
use App\Support\OrgUnits;
use Illuminate\Http\Request;

/**
 * SMS from the AFP page: the text and recipient for a due or overdue case, sending it, its delivery status, and
 * the district officers' numbers. Everything is limited to the request's region; in the Ghana view a region is
 * named in the call.
 */
class SmsController extends Controller
{
    private function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\InvalidArgumentException $e) {
            return Access::error(400, $e->getMessage());
        } catch (\RuntimeException $e) {
            return Access::error(502, $e->getMessage());
        }
    }

    /** The region whose officers are listed or changed: the request's own, or the one named in the Ghana view. */
    private function region(Request $request, ?string $named): array
    {
        $here = Access::unit($request);
        if (! OrgUnits::isNational($here)) {
            return $here;
        }

        return OrgUnits::regionOf($named) ?? throw new \InvalidArgumentException('Choose a region.');
    }

    public function preview(Request $request)
    {
        return $this->run(fn () => Access::ok(
            Sms::preview(strtoupper(trim((string) $request->query('epid', ''))), Access::unit($request))));
    }

    public function send(Request $request)
    {
        return $this->run(function () use ($request) {
            $epid = strtoupper(trim((string) (Access::body($request)['epid'] ?? '')));

            return Access::ok(['sms' => Sms::send($epid, Access::unit($request), $request->user()->username)]);
        });
    }

    /** The delivery status of one logged SMS, asked of the SMS service again. */
    public function status(Request $request)
    {
        $m = SmsMessage::find((int) $request->query('id'));
        $here = Access::unit($request);
        if (! $m || (! OrgUnits::isNational($here) && $m->region !== $here['name'])) {
            return Access::error(404, 'No such SMS.');
        }
        Sms::refresh($m);

        return Access::ok(['sms' => Sms::lastSent([$m->epid])[$m->epid]]);
    }

    // ---------- the SMS account (support only) ----------
    private function settingsView(): array
    {
        $a = LetsFish::account();
        $id = $a['app_id'];

        return ['source' => $a['source'], 'appId' => $a['source'] === 'app' ? $id : '',
            'appIdHint' => $id === '' ? '' : (strlen($id) > 6 ? substr($id, 0, 3).'…'.substr($id, -3) : '…'),
            'secretSet' => $a['source'] !== null, 'senderId' => $a['sender_id'],
            'updatedBy' => AppSetting::find('letsfish.app_id')?->updated_by,
            'updatedAt' => AppSetting::find('letsfish.app_id')?->updated_at?->format('Y-m-d\TH:i')];
    }

    /** GET: how SMS is set up (never the secret). POST: save the App ID, App Secret (blank keeps it) and sender ID. */
    public function settings(Request $request)
    {
        if (! Access::isSupport($request)) {
            return Access::error(403, 'Only the support account changes the SMS settings.');
        }
        if ($request->isMethod('get')) {
            return Access::ok($this->settingsView());
        }

        return $this->run(function () use ($request) {
            $d = Access::body($request);
            $by = $request->user()->username;
            if (! empty($d['remove'])) {
                AppSetting::whereIn('name', ['letsfish.app_id', 'letsfish.app_secret', 'letsfish.sender_id'])->delete();

                return Access::ok($this->settingsView());
            }
            $id = trim((string) ($d['appId'] ?? ''));
            $secret = trim((string) ($d['appSecret'] ?? ''));
            $sender = trim((string) ($d['senderId'] ?? ''));
            if ($id === '' || preg_match('/\s/', $id)) {
                throw new \InvalidArgumentException('Enter the App ID from the Fish Africa dashboard (Settings > Applications > Default App).');
            }
            if ($secret === '' && ! AppSetting::read('letsfish.app_secret', true)) {
                throw new \InvalidArgumentException('Enter the App Secret too.');
            }
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\-]{0,10}$/', $sender)) {
                throw new \InvalidArgumentException('The sender ID is what phones show as the sender: up to 11 letters or numbers, like GHS-AFP. It must be approved on your Fish Africa account.');
            }
            AppSetting::write('letsfish.app_id', $id, $by);
            if ($secret !== '') {
                AppSetting::write('letsfish.app_secret', $secret, $by, true);
            }
            AppSetting::write('letsfish.sender_id', $sender, $by);

            return Access::ok($this->settingsView());
        });
    }

    /** Send a test SMS with the saved account, to check it works. */
    public function test(Request $request)
    {
        if (! Access::isSupport($request)) {
            return Access::error(403, 'Only the support account changes the SMS settings.');
        }

        return $this->run(function () use ($request) {
            $phone = Sms::phone(Access::body($request)['phone'] ?? '');
            $r = LetsFish::send($phone, 'Test message from AFP Watch Out: SMS to district officers is working. - GHS Surveillance');

            return Access::ok(['to' => Sms::local($phone), 'status' => $r['status'], 'reference' => $r['reference']]);
        });
    }

    public function officers(Request $request)
    {
        return $this->run(function () use ($request) {
            if ($request->isMethod('get')) {
                $r = $this->region($request, $request->query('region'));

                return Access::ok(['region' => $r['name'], 'officers' => Sms::officers($r)]);
            }
            $d = Access::body($request);
            $r = $this->region($request, $d['region'] ?? null);
            Sms::saveOfficer($r, (string) ($d['code'] ?? ''), (string) ($d['name'] ?? ''), (string) ($d['phone'] ?? ''),
                $request->user()->username);

            return Access::ok(['region' => $r['name'], 'officers' => Sms::officers($r)]);
        });
    }
}
