<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pages send the CSRF token as X-CSRFToken (their header name from before); Laravel checks X-XSRF-TOKEN.
 */
class CsrfHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->has('X-CSRFToken') && ! $request->headers->has('X-XSRF-TOKEN')) {
            $request->headers->set('X-XSRF-TOKEN', $request->headers->get('X-CSRFToken'));
        }

        return $next($request);
    }
}
