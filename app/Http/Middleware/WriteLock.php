<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One save at a time. The workbooks are read, changed and written whole, and the host may run the app in several
 * processes: two saves at once could each overwrite the other's change. Every request that can change something
 * holds a lock file in DATA_DIR while it runs; reads never wait.
 */
class WriteLock
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        $dir = config('surveillance.data_dir');
        @mkdir($dir, 0700, true);
        $f = fopen($dir.'/.write.lock', 'c');
        flock($f, LOCK_EX);
        try {
            return $next($request);
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }
}
