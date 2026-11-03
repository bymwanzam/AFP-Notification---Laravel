<?php

namespace App\Services;

use App\Afp\Cases;
use App\Models\DistrictOfficer;
use App\Models\SmsMessage;
use App\Support\OrgUnits;
use DateTimeImmutable;

/**
 * SMS to district surveillance officers about AFP cases whose 60-day follow-up is past due or due within 7 days.
 * Each district has one officer's number (kept in the database, set on the AFP page); every SMS is logged.
 * Problems are thrown as \InvalidArgumentException with the message to show.
 */
class Sms
{
    public const FUP = 60;
    public const SOON = 7;

    public static function configured(): bool
    {
        return LetsFish::configured();
    }

    /** A Ghana mobile number as 233XXXXXXXXX: from 024 123 4567, +233 24 123 4567 or 233241234567. */
    public static function phone($text): string
    {
        $d = preg_replace('/\D/', '', (string) $text);
        if (strlen($d) === 10 && $d[0] === '0') {
            $d = '233'.substr($d, 1);
        } elseif (strlen($d) === 9) {
            $d = '233'.$d;
        }
        if (! preg_match('/^233[2-5]\d{8}$/', $d)) {
            throw new \InvalidArgumentException('Enter a Ghana mobile number, like 024 123 4567.');
        }

        return $d;
    }

    /** 233241234567 as 024 123 4567. */
    public static function local(string $phone): string
    {
        $n = '0'.substr($phone, 3);

        return substr($n, 0, 3).' '.substr($n, 3, 3).' '.substr($n, 6);
    }

    /** The case's follow-up status the way the AFP page works it out: past, soon, or null. */
    public static function classify(array $c, ?DateTimeImmutable $today = null): ?array
    {
        $today ??= new DateTimeImmutable('today');
        $onset = ! empty($c['onset']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $c['onset'])
            ? new DateTimeImmutable(substr($c['onset'], 0, 10)) : null;
        $done = strtolower(trim((string) ($c['outcome'] ?? ''))) === 'fup done';
        if (! $onset || $done) {
            return null;
        }
        $days = (int) $onset->diff($today)->format('%r%a');
        $left = self::FUP - $days;
        $fup = $onset->modify('+'.self::FUP.' days');
        $key = $days > self::FUP ? 'past' : ($left <= self::SOON ? 'soon' : null);

        return $key ? ['key' => $key, 'days' => $days, 'left' => $left, 'fup' => $fup, 'onset' => $onset] : null;
    }

    /** [region, district code, district name] of a case's reporting district. */
    public static function place(array $c): array
    {
        [$r, $d] = OrgUnits::districtRegion($c['district'] ?? null);
        if ($r) {
            return [$r['name'], $d[0], $d[1]];
        }
        $r = OrgUnits::caseRegion($c['district'] ?? null);

        return [$r['name'], strtoupper((string) ($c['code'] ?? '')), (string) ($c['district'] ?? '')];
    }

    public static function officer(string $region, string $code): ?DistrictOfficer
    {
        return $code === '' ? null : DistrictOfficer::where('region', $region)->where('district_code', $code)->first();
    }

    /** The SMS text for a case. Kept short: one or two SMS pages. */
    public static function message(array $c, array $st, string $region): string
    {
        $who = trim(($c['name'] ?? '') ?: 'Case').' ('.$c['epid'].')';
        $place = $c['district'] ?? '';
        $due = $st['fup']->format('d/m/Y');
        if ($st['key'] === 'past') {
            $late = $st['days'] - self::FUP;
            $head = "AFP 60-day follow-up OVERDUE by $late day".($late === 1 ? '' : 's');
            $ask = 'Please do the follow-up visit and send the report.';
        } else {
            $head = 'AFP 60-day follow-up due '.($st['left'] <= 0 ? 'TODAY' : 'in '.$st['left'].' day'.($st['left'] === 1 ? '' : 's'));
            $ask = 'Please plan the follow-up visit.';
        }

        return "$head: $who, $place. Onset {$st['onset']->format('d/m/Y')}, visit due $due. $ask - $region RHD Surveillance";
    }

    /** The case in the unit with this EPID #, or a problem. */
    public static function findCase(string $epid, array $unit): array
    {
        foreach (Cases::regionCases(Cases::registerPath(), $unit) as $c) {
            if (strtoupper(trim((string) ($c['epid'] ?? ''))) === $epid) {
                return $c;
            }
        }
        throw new \InvalidArgumentException("There is no case $epid in this region.");
    }

    /** What would be sent for a case: the officer, number and text, or why it can't be sent. */
    public static function preview(string $epid, array $unit): array
    {
        $c = self::findCase($epid, $unit);
        $st = self::classify($c) ?? throw new \InvalidArgumentException("$epid is not past due or due within 7 days, so no SMS is needed.");
        [$region, $code, $district] = self::place($c);
        $o = self::officer($region, $code);
        $last = self::lastSent([$epid])[$epid] ?? null;

        return ['epid' => $epid, 'status' => $st['key'], 'region' => $region, 'code' => $code, 'district' => $district,
            'officer' => $o ? ['name' => $o->name, 'phone' => self::local($o->phone)] : null,
            'message' => self::message($c, $st, $region), 'last' => $last, 'ready' => self::configured()];
    }

    /** Send the case's SMS to its district officer, unless one went out for the same status recently. */
    public static function send(string $epid, array $unit, string $by): array
    {
        $p = self::preview($epid, $unit);
        if (! $p['officer']) {
            throw new \InvalidArgumentException("No officer's number is saved for {$p['district']}. Add it under SMS contacts first.");
        }
        $hours = config('surveillance.sms.repeat_hours');
        $recent = SmsMessage::where('epid', $epid)->where('status_key', $p['status'])->where('status', '!=', 'failed')
            ->where('created_at', '>', now()->subHours($hours))->latest()->first();
        if ($recent) {
            throw new \InvalidArgumentException('An SMS about '.$epid.' went to '.$recent->officer.' at '
                .$recent->created_at->format('d M H:i').". It can be sent again after $hours hours.");
        }
        $o = self::officer($p['region'], $p['code']);
        $log = new SmsMessage(['epid' => $epid, 'status_key' => $p['status'], 'region' => $p['region'],
            'district' => $p['district'], 'officer' => $o->name ?: 'District officer', 'phone' => $o->phone,
            'message' => $p['message'], 'sent_by' => $by]);
        try {
            $r = LetsFish::send($o->phone, $p['message']);
            $log->fill(['reference' => $r['reference'], 'status' => $r['status'] ?: 'queued'])->save();
        } catch (\RuntimeException $e) {
            $log->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255)])->save();
            throw $e;
        }

        return self::lastSent([$epid])[$epid];
    }

    /** [EPID => the last SMS sent about it] for the AFP page. */
    public static function lastSent(array $epids): array
    {
        $epids = array_values(array_filter(array_map(fn ($e) => strtoupper(trim((string) $e)), $epids)));
        if (! $epids) {
            return [];
        }
        $out = [];
        foreach (SmsMessage::whereIn('epid', $epids)->orderBy('id')->get() as $m) {
            $out[$m->epid] = ['at' => $m->created_at->format('Y-m-d\TH:i'), 'to' => $m->officer, 'phone' => self::local($m->phone),
                'by' => $m->sent_by, 'status' => $m->status, 'error' => $m->error, 'id' => $m->id];
        }

        return $out;
    }

    /** Ask the SMS service again how a sent SMS is doing, and keep the answer. */
    public static function refresh(SmsMessage $m): SmsMessage
    {
        if ($m->reference !== '' && ! in_array($m->status, ['delivered', 'failed'], true)) {
            if ($s = LetsFish::status($m->reference)) {
                $m->status = $s;
                $m->save();
            }
        }

        return $m;
    }

    // ---------- the officers' numbers ----------
    /** Each district of the region with its officer, for the SMS contacts list. */
    public static function officers(array $region): array
    {
        $saved = DistrictOfficer::where('region', $region['name'])->get()->keyBy('district_code');

        return array_map(function ($d) use ($saved) {
            $o = $saved[$d[0]] ?? null;

            return ['code' => $d[0], 'district' => $d[1], 'name' => $o?->name ?? '', 'phone' => $o ? self::local($o->phone) : ''];
        }, $region['districts']);
    }

    /** Save (or, with no number, remove) a district officer's name and number. */
    public static function saveOfficer(array $region, string $code, string $name, string $phone, string $by): void
    {
        $d = null;
        foreach ($region['districts'] as $x) {
            if ($x[0] === $code) {
                $d = $x;
            }
        }
        if (! $d) {
            throw new \InvalidArgumentException("$code is not a district of {$region['name']} Region.");
        }
        if (trim($phone) === '') {
            DistrictOfficer::where('region', $region['name'])->where('district_code', $code)->delete();

            return;
        }
        $name = trim($name);
        if (mb_strlen($name) > 80) {
            throw new \InvalidArgumentException('Keep the name under 80 characters.');
        }
        DistrictOfficer::updateOrCreate(['region' => $region['name'], 'district_code' => $code],
            ['district' => $d[1], 'name' => $name, 'phone' => self::phone($phone), 'updated_by' => $by]);
    }
}
