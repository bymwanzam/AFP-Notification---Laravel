<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An API call for signed-in accounts, refused with JSON (not a redirect) so a page whose sign-in ended can say so
 * and keep what was typed. "api:change" also lets through an account that must still change its password.
 */
class Api
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();
        if (! $user) {
            return Access::error(401, 'Your session has ended. Sign in again.');
        }
        if ($user->must_change && $mode !== 'change') {
            return Access::error(403, 'Change your password first.');
        }

        return NoCache::apply($next($request));
    }
}
