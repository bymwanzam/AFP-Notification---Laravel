<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page for signed-in accounts whose password needs no change; everyone else goes to the login page (which asks
 * for the new password when that is what is missing).
 */
class Page
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->must_change) {
            return redirect('/login?next='.rawurlencode($request->getRequestUri()));
        }

        return NoCache::apply($next($request));
    }
}
