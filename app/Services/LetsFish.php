<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;

/**
 * Fish Africa's SMS API (https://docs.letsfish.africa): POST /sms with a sender ID, the message and the recipients
 * (233XXXXXXXXX); GET /sms/{reference} for the delivery status. Requests carry "Authorization: Bearer
 * <app_id>.<app_secret>". Failures are thrown as \RuntimeException with a message people can act on.
 */
class LetsFish
{
    /**
     * The account in use: what the support account saved under SMS settings, else .env (LETSFISH_*).
     * ['app_id', 'app_secret', 'sender_id', 'source' => 'app' | 'env' | null]
     */
    public static function account(): array
    {
        $id = AppSetting::read('letsfish.app_id');
        $secret = AppSetting::read('letsfish.app_secret', true);
        $sender = AppSetting::read('letsfish.sender_id');
        if ($id && $secret) {
            return ['app_id' => $id, 'app_secret' => $secret, 'sender_id' => $sender ?: config('surveillance.sms.sender_id'), 'source' => 'app'];
        }
        $c = config('surveillance.sms');
        $set = ($c['app_id'] ?? '') !== '' && ($c['app_secret'] ?? '') !== '';

        return ['app_id' => $c['app_id'] ?? '', 'app_secret' => $c['app_secret'] ?? '',
            'sender_id' => $sender ?: $c['sender_id'], 'source' => $set ? 'env' : null];
    }

    public static function configured(): bool
    {
        return self::account()['source'] !== null;
    }

    private static function client(?array $a = null)
    {
        $a ??= self::account();

        return Http::baseUrl(config('surveillance.sms.url'))->withToken($a['app_id'].'.'.$a['app_secret'])->acceptJson()->timeout(20);
    }

    /** Send one SMS. Returns ['reference' => ..., 'status' => 'queued' | ...]. $account: try other credentials. */
    public static function send(string $phone, string $message, ?array $account = null): array
    {
        $a = $account ?? self::account();
        if (($a['app_id'] ?? '') === '' || ($a['app_secret'] ?? '') === '') {
            throw new \RuntimeException('SMS is not set up yet. The support account can add the Fish Africa App ID and App Secret under SMS settings.');
        }
        try {
            $r = self::client($a)->post('sms', ['sender_id' => $a['sender_id'], 'message' => $message, 'recipients' => [$phone]]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not reach the SMS service. Check the internet connection and try again.');
        }
        if (! $r->successful()) {
            throw new \RuntimeException(self::problem($r->status(), $r->json()));
        }
        $item = $r->json('data.0') ?? [];

        return ['reference' => (string) ($item['reference'] ?? ''), 'status' => (string) ($item['status'] ?? 'queued')];
    }

    /** The delivery status of a sent SMS (queued, sent, delivered, failed, ...), or null when it can't be read. */
    public static function status(string $reference): ?string
    {
        if (! self::configured() || $reference === '') {
            return null;
        }
        try {
            $r = self::client()->get('sms/'.rawurlencode($reference));
        } catch (\Throwable) {
            return null;
        }

        return $r->successful() ? ($r->json('data.status') ?: null) : null;
    }

    private static function problem(int $status, $body): string
    {
        $detail = is_array($body) ? trim(($body['error']['title'] ?? '').': '.($body['error']['detail'] ?? ''), ': ') : '';

        return match ($status) {
            401, 403 => 'Fish Africa refused the App ID or App Secret. Check them under SMS settings.',
            402 => 'The SMS account has no credit left. Top it up on the Fish Africa dashboard.',
            429 => 'Too many SMS at once. Wait a minute and try again.',
            default => 'The SMS service could not send it ('.$status.($detail ? ': '.$detail : '').').',
        };
    }
}
