<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pages and API answers that show someone's data are never kept by the browser or a proxy. */
class NoCache
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request));
    }

    public static function apply(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'max-age=0, no-cache, no-store, must-revalidate, private');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
