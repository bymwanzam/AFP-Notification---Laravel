<?php

namespace App\Support;

use App\Services\Accounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The region a request works for, and the JSON answers the pages expect.
 *
 * Each account belongs to one region and works with it and its districts only. Support and national
 * administrators see every region: they pick one, or Ghana (all regions), on the start page for that sign-in.
 */
class Access
{
    public static function error(int $status, string $message, array $extra = []): JsonResponse
    {
        return response()->json(['error' => $message] + $extra, $status, [], JSON_UNESCAPED_UNICODE);
    }

    public static function ok($data): JsonResponse
    {
        return response()->json($data, 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Only a path on this app, never another site ("//host" or "/\host"). */
    public static function safeNext($path): string
    {
        $path = (string) ($path ?? '');

        return preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $path) && ! str_starts_with($path, '/login') && ! str_starts_with($path, '/logout')
            ? $path : '/start';
    }

    /** The request's JSON body as an array; throws when it isn't a JSON object. */
    public static function body(Request $request): array
    {
        $d = json_decode($request->getContent() ?: '{}', true);
        if (! is_array($d) || array_is_list($d) && $d !== []) {
            throw new \InvalidArgumentException('The request was not understood.');
        }

        return $d;
    }

    public static function seesAll(Request $request): bool
    {
        return Accounts::seesAll($request->user());
    }

    public static function isSupport(Request $request): bool
    {
        return Accounts::isSupport($request->user());
    }

    /** A regional account's own region; for support and national the one picked for this sign-in. */
    public static function unit(Request $request): array
    {
        $user = $request->user();
        if (! Accounts::seesAll($user)) {
            return Accounts::userRegion($user);
        }
        $view = $request->session()->get('view') ?: (Accounts::isNational($user) ? OrgUnits::NATIONAL : null);

        return $view === OrgUnits::NATIONAL ? OrgUnits::nationalUnit() : (OrgUnits::regionOf($view) ?? OrgUnits::currentRegion());
    }

    /** The region name a write is limited to, or null in the national view (cases of any region). */
    public static function scope(Request $request): ?string
    {
        $u = self::unit($request);

        return OrgUnits::isNational($u) ? null : $u['name'];
    }
}
